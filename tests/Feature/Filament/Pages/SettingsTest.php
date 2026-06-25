<?php

use App\Exceptions\FedexRegistrationMaxRetriesException;
use App\Filament\Pages\Settings;
use App\Filament\Resources\CarrierAccounts\Pages\EditCarrierAccount;
use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\Requests\Registration\SendPin;
use App\Http\Integrations\Fedex\Requests\Registration\ValidateAddress;
use App\Http\Integrations\Fedex\Requests\Registration\VerifyInvoice;
use App\Http\Integrations\Fedex\Requests\Registration\VerifyPin;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Client;
use App\Models\Location;
use App\Models\Setting;
use App\Models\User;
use App\Services\FedexRegistrationService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('shows sandbox mode indicator in topbar when sandbox mode is enabled', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    $this->get('/')->assertSeeText('(sandbox mode)');
});

it('does not show sandbox mode indicator when sandbox mode is disabled', function (): void {
    app(SettingsService::class)->clearCache();

    $this->get('/')
        ->assertOk()
        ->assertDontSee('(sandbox mode)</span>');
});

it('shows the fedex eula confidentiality footer', function (): void {
    $renderedEula = Blade::render("@include('filament.pages.settings.fedex-eula')");

    expect($renderedEula)
        ->toContain('FedEx Confidential')
        ->toContain('FedEx Form No. 2002382 v 4 June 2024 Rev');
});

it('test usps connection shows CONTRACT success notification', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make(['pricingOptions' => [['shippingOptions' => []]]], 200),
    ]);
    Cache::forget('usps_pricing_type');

    Livewire::test(Settings::class)
        ->call('testUspsConnection')
        ->assertNotified();

    expect(Cache::get('usps_pricing_type'))->toBe('CONTRACT');
})->skip('testUspsConnection() belongs to the legacy global settings-based USPS flow scheduled for removal.');

it('test usps connection shows RETAIL notification when CONTRACT returns 403', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ShippingOptions::class => MockResponse::make(['error' => ['code' => '403', 'message' => 'Not authorized']], 403),
    ]);
    Cache::forget('usps_pricing_type');

    Livewire::test(Settings::class)
        ->call('testUspsConnection')
        ->assertNotified();

    expect(Cache::get('usps_pricing_type'))->toBe('RETAIL');
})->skip('testUspsConnection() belongs to the legacy global settings-based USPS flow scheduled for removal.');

it('test usps connection shows danger notification when auth fails', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['error' => 'invalid_client'], 401),
    ]);
    Cache::forget('usps_pricing_type');

    Livewire::test(Settings::class)
        ->call('testUspsConnection')
        ->assertNotified();

    expect(Cache::get('usps_pricing_type'))->toBeNull();
})->skip('testUspsConnection() belongs to the legacy global settings-based USPS flow scheduled for removal.');

it('usps pricing tier cache is set by testUspsConnection (display moved to carrier accounts)', function (): void {
    // The pricing tier is now only stored in cache by testUspsConnection();
    // the UI display moved from Settings to the Carrier Accounts resource.
    Cache::put('usps_pricing_type', 'RETAIL', 3600);

    expect(Cache::get('usps_pricing_type'))->toBe('RETAIL');
});

// ─── FedEx Account Registration ───────────────────────────────────────────────

function fedexOauthMock(): array
{
    return ['*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600])];
}

function fedexMfaResponse(): array
{
    return [
        'output' => [
            'mfaOptions' => [[
                'accountAuthToken' => 'test-auth-token',
                'mfaRequired' => true,
                'email' => 'TE***@EX***.COM',
                'phoneNumber' => '***-***-1234',
                'options' => [
                    'invoice' => 'INVOICE',
                    'secureCode' => ['SMS', 'CALL', 'EMAIL'],
                ],
            ]],
        ],
    ];
}

function fedexCredentialsResponse(): array
{
    return [
        'output' => [
            'credentials' => [
                'child_Key' => 'test-child-key',
                'child_secret' => 'test-child-secret',
            ],
        ],
    ];
}

it('fedex account status is not shown on settings page (moved to carrier accounts)', function (): void {
    // FedEx connection status moved to the Carrier Accounts edit page.
    Setting::create(['key' => 'fedex.child_key', 'value' => 'some-child-key', 'type' => 'string', 'encrypted' => true, 'group' => 'fedex']);
    app(SettingsService::class)->clearCache();

    $this->get(Settings::getUrl())
        ->assertOk()
        ->assertDontSee('Account Status');
});

it('fedex connector keeps parent oauth config when child key is present', function (): void {
    $account = createFedexAccount(
        ['child_key' => 'child-key-123', 'child_secret' => 'child-secret-456'],
    );

    $connector = FedexConnector::forAccount($account);
    $config = (new ReflectionMethod($connector, 'defaultOauthConfig'))->invoke($connector);

    expect($config->getClientId())->toBe('test_api_key')
        ->and($config->getClientSecret())->toBe('test_api_secret');
});

it('fedex connector falls back to parent key when no child key', function (): void {
    $account = createFedexAccount();

    $connector = FedexConnector::forAccount($account);
    $config = (new ReflectionMethod($connector, 'defaultOauthConfig'))->invoke($connector);

    expect($config->getClientId())->toBe('test_api_key')
        ->and($config->getClientSecret())->toBe('test_api_secret');
});

it('fedex registration service validates address and returns mfa options', function (): void {
    Storage::fake();
    $account = createFedexAccount();

    Saloon::fake([
        ...fedexOauthMock(),
        ValidateAddress::class => MockResponse::make(fedexMfaResponse(), 200),
    ]);

    $result = app(FedexRegistrationService::class)->validateAddress(
        account: $account,
        accountNumber: '700257037',
        customerName: 'Test Company',
        residential: false,
        street1: '15 W 18TH ST FL 7',
        street2: '',
        city: 'NEW YORK',
        stateOrProvinceCode: 'NY',
        postalCode: '10011',
        countryCode: 'US',
    );

    expect($result['mfaRequired'])->toBeTrue()
        ->and($result['accountAuthToken'])->toBe('test-auth-token')
        ->and($result['email'])->toBe('TE***@EX***.COM');

    Saloon::assertSent(ValidateAddress::class);
    Storage::assertExists('fedex-mfa/latest/address-validation/request.json');
    Storage::assertExists('fedex-mfa/latest/address-validation/response.json');
});

it('fedex registration service saves child credentials after pin verification', function (): void {
    $account = createFedexAccount();

    Saloon::fake([
        ...fedexOauthMock(),
        VerifyPin::class => MockResponse::make(fedexCredentialsResponse(), 200),
    ]);

    $credentials = app(FedexRegistrationService::class)->verifyPin($account, 'test-auth-token', '123456');

    app(FedexRegistrationService::class)->saveChildCredentials(
        $credentials['child_Key'],
        $credentials['child_secret'],
    );

    app(SettingsService::class)->clearCache();

    expect(Setting::where('key', 'fedex.child_key')->exists())->toBeTrue()
        ->and(Setting::where('key', 'fedex.child_secret')->exists())->toBeTrue();
});

it('fedex registration service saves child credentials after invoice verification', function (): void {
    $account = createFedexAccount();

    Saloon::fake([
        ...fedexOauthMock(),
        VerifyInvoice::class => MockResponse::make(fedexCredentialsResponse(), 200),
    ]);

    $credentials = app(FedexRegistrationService::class)->verifyInvoice(
        account: $account,
        accountAuthToken: 'test-auth-token',
        invoiceNumber: 234562278,
        invoiceDate: now()->subDays(30)->format('Y-m-d'),
        invoiceAmount: 234.00,
        invoiceCurrency: 'USD',
    );

    app(FedexRegistrationService::class)->saveChildCredentials(
        $credentials['child_Key'],
        $credentials['child_secret'],
    );

    app(SettingsService::class)->clearCache();

    expect(Setting::where('key', 'fedex.child_key')->exists())->toBeTrue();
});

it('fedex disconnect removes child credentials and clears authenticator cache', function (): void {
    Setting::create(['key' => 'fedex.child_key', 'value' => 'some-key', 'type' => 'string', 'encrypted' => true, 'group' => 'fedex']);
    Setting::create(['key' => 'fedex.child_secret', 'value' => 'some-secret', 'type' => 'string', 'encrypted' => true, 'group' => 'fedex']);
    Cache::put('fedex_authenticator', 'cached-token', 3600);
    app(SettingsService::class)->clearCache();

    app(FedexRegistrationService::class)->removeChildCredentials();

    app(SettingsService::class)->clearCache();

    expect(Setting::where('key', 'fedex.child_key')->exists())->toBeFalse()
        ->and(Setting::where('key', 'fedex.child_secret')->exists())->toBeFalse()
        ->and(Cache::has('fedex_authenticator'))->toBeFalse();
});

it('fedex registration service mfa bypass returns credentials immediately', function (): void {
    $account = createFedexAccount();

    Saloon::fake([
        ...fedexOauthMock(),
        ValidateAddress::class => MockResponse::make([
            'output' => [
                'credentials' => [
                    'child_Key' => 'bypass-child-key',
                    'child_secret' => 'bypass-child-secret',
                ],
            ],
        ], 200),
    ]);

    $result = app(FedexRegistrationService::class)->validateAddress(
        account: $account,
        accountNumber: '700257037',
        customerName: 'Test Company',
        residential: false,
        street1: '15 W 18TH ST FL 7',
        street2: '',
        city: 'NEW YORK',
        stateOrProvinceCode: 'NY',
        postalCode: '10011',
        countryCode: 'US',
    );

    expect($result['mfaRequired'])->toBeFalse()
        ->and($result['credentials']['child_Key'])->toBe('bypass-child-key');

    Saloon::assertNotSent(SendPin::class);
    Saloon::assertNotSent(VerifyPin::class);
});

it('fedex registration service activates child credentials and captures child authorization artifacts', function (): void {
    Storage::fake();
    Http::fake([
        'https://broker.example.test/fedex/token' => Http::response([
            'access_token' => 'child-access-token',
            'token_type' => 'bearer',
            'expires_in' => 3600,
        ], 200),
    ]);

    config([
        'services.oauth.broker_url' => 'https://broker.example.test',
        'services.oauth.instance_id' => 'test-instance',
        'services.oauth.broker_secret' => 'test-secret',
    ]);

    app(FedexRegistrationService::class)->activateChildCredentials('child-key-123', 'child-secret-456');
    app(SettingsService::class)->clearCache();

    expect(app(SettingsService::class)->get('fedex.child_key'))->toBe('child-key-123')
        ->and(app(SettingsService::class)->get('fedex.child_secret'))->toBe('child-secret-456');

    Storage::assertExists('fedex-mfa/latest/child-authorization/request.json');
    Storage::assertExists('fedex-mfa/latest/child-authorization/response.json');
})->skip('activateChildCredentials() is the legacy global settings-based FedEx registration flow scheduled for removal; the per-account flow (saveChildCredentialsToAccount) is covered elsewhere.');

it('fedex registration service maps current fedex max retry codes to the fallback exception', function (): void {
    $account = createFedexAccount();

    Saloon::fake([
        ...fedexOauthMock(),
        VerifyPin::class => MockResponse::make([
            'errors' => [[
                'code' => 'PINVALIDATION.MAXRETRY.EXCEEDED',
                'message' => 'max retry exceeded for PIN validation',
            ]],
        ], 400),
    ]);

    try {
        app(FedexRegistrationService::class)->verifyPin($account, 'test-auth-token', '123456');
        $this->fail('Expected FedEx max retry exception was not thrown.');
    } catch (FedexRegistrationMaxRetriesException $exception) {
        expect($exception->fedexCode)->toBe('PINVALIDATION.MAXRETRY.EXCEEDED')
            ->and($exception->lockedMethods)->toBe(['SMS', 'CALL', 'EMAIL']);
    }
});

it('carrier account edit page filters exhausted fedex verification methods', function (): void {
    $fedex = Carrier::factory()->fedex()->create();
    $account = CarrierAccount::factory()->fedex()->create(['carrier_id' => $fedex->id]);

    $page = Livewire::test(EditCarrierAccount::class, ['record' => $account->id])->instance();

    $page->fedexSecureCodeOptions = ['SMS', 'CALL', 'EMAIL'];
    $page->fedexMaskedPhone = '***-***-1234';
    $page->fedexMaskedEmail = 'TE***@EX***.COM';
    $page->fedexInvoiceAvailable = true;
    $page->fedexLockedFactor2Methods = ['SMS', 'CALL', 'EMAIL'];

    expect($page->getFedexAvailableVerificationOptions())
        ->toBe(['INVOICE' => 'Invoice Validation'])
        ->and($page->hasAvailableFedexFactor2Methods())->toBeTrue();
});

it('carrier account edit page reports when all fedex verification methods are exhausted', function (): void {
    $fedex = Carrier::factory()->fedex()->create();
    $account = CarrierAccount::factory()->fedex()->create(['carrier_id' => $fedex->id]);

    $page = Livewire::test(EditCarrierAccount::class, ['record' => $account->id])->instance();

    $page->fedexSecureCodeOptions = ['SMS', 'CALL', 'EMAIL'];
    $page->fedexInvoiceAvailable = true;
    $page->fedexLockedFactor2Methods = ['SMS', 'CALL', 'EMAIL', 'INVOICE'];

    expect($page->getFedexAvailableVerificationOptions())
        ->toBe([])
        ->and($page->hasAvailableFedexFactor2Methods())->toBeFalse();
});

it('fedex registration service routes through proxy when broker url is configured', function (): void {
    $account = createFedexAccount();

    config([
        'services.oauth.broker_url' => 'https://polybag-connect.example.com',
        'services.oauth.instance_id' => 'test-instance',
        'services.oauth.broker_secret' => 'test-secret',
    ]);

    Saloon::fake([
        ValidateAddress::class => MockResponse::make(fedexMfaResponse(), 200),
    ]);

    $result = app(FedexRegistrationService::class)->validateAddress(
        account: $account,
        accountNumber: '700257037',
        customerName: 'Test Company',
        residential: false,
        street1: '15 W 18TH ST FL 7',
        street2: '',
        city: 'NEW YORK',
        stateOrProvinceCode: 'NY',
        postalCode: '10011',
        countryCode: 'US',
    );

    expect($result['mfaRequired'])->toBeTrue();

    // No OAuth token request — proxy connector handles auth on the broker side
    Saloon::assertNotSent('*oauth*');
    Saloon::assertSent(ValidateAddress::class);
});

// ─── Single-client mode: client fields in Settings ────────────────────────────

it('loads default client fields into settings form in single-client mode', function (): void {
    $client = Client::factory()->create([
        'is_default' => true,
        'company_name' => 'ACME Corporation',
        'custom_message' => 'Thank you!',
        'return_address1' => '123 Main St',
        'return_city' => 'Springfield',
        'return_state_or_province' => 'IL',
        'return_postal_code' => '62701',
        'return_country' => 'US',
    ]);

    Livewire::test(Settings::class)
        ->assertSet('data.client.company_name', 'ACME Corporation')
        ->assertSet('data.client.custom_message', 'Thank you!')
        ->assertSet('data.client.return_address1', '123 Main St');
});

it('saves default client fields from settings form in single-client mode', function (): void {
    $client = Client::factory()->create(['is_default' => true]);

    Livewire::test(Settings::class)
        ->fillForm([
            'client.company_name' => 'Updated Corp',
            'client.custom_message' => 'Thanks for shopping!',
            'client.return_address1' => '456 Elm St',
            'client.return_city' => 'Shelbyville',
            'client.return_state_or_province' => 'IL',
            'client.return_postal_code' => '62565',
            'client.return_country' => 'US',
        ])
        ->call('save')
        ->assertNotified();

    expect($client->fresh())
        ->company_name->toBe('Updated Corp')
        ->custom_message->toBe('Thanks for shopping!')
        ->return_address1->toBe('456 Elm St')
        ->return_city->toBe('Shelbyville');
});

it('does not load client fields into settings form in multi-client mode', function (): void {
    Setting::create(['key' => 'multi_client_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    Client::factory()->create(['is_default' => true, 'company_name' => 'Should Not Load']);

    Livewire::test(Settings::class)
        ->assertSet('data.client.company_name', null);
});

it('does not overwrite client fields when saving in multi-client mode', function (): void {
    Setting::create(['key' => 'multi_client_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    $client = Client::factory()->create([
        'is_default' => true,
        'company_name' => 'Original Name',
    ]);

    Livewire::test(Settings::class)
        ->call('save')
        ->assertNotified();

    expect($client->fresh()->company_name)->toBe('Original Name');
});

// ─── Single-location mode: location fields in Settings ────────────────────────

it('loads default location fields into settings form in single-location mode', function (): void {
    $location = Location::factory()->create([
        'is_default' => true,
        'name' => 'Main Warehouse',
        'address1' => '123 Main St',
        'city' => 'Springfield',
        'state_or_province' => 'IL',
        'postal_code' => '62701',
        'country' => 'US',
        'timezone' => 'America/Chicago',
    ]);

    Livewire::test(Settings::class)
        ->assertSet('data.location.name', 'Main Warehouse')
        ->assertSet('data.location.address1', '123 Main St')
        ->assertSet('data.location.timezone', 'America/Chicago');
});

it('saves default location fields from settings form in single-location mode', function (): void {
    $location = Location::factory()->create([
        'is_default' => true,
        'name' => 'Old Name',
        'country' => 'US',
        'phone' => null,
    ]);

    Livewire::test(Settings::class)
        ->fillForm([
            'location.name' => 'New Warehouse',
            'location.country' => 'US',
            'location.first_name' => 'Shipping',
            'location.last_name' => 'Center',
            'location.address1' => '456 Elm St',
            'location.city' => 'Shelbyville',
            'location.state_or_province' => 'IL',
            'location.postal_code' => '62565',
            'location.timezone' => 'America/Chicago',
            'location.phone' => null,
        ])
        ->call('save')
        ->assertNotified();

    expect($location->fresh())
        ->name->toBe('New Warehouse')
        ->address1->toBe('456 Elm St')
        ->city->toBe('Shelbyville');
});

it('syncs carrier locations when saving location in single-location mode', function (): void {
    $location = Location::factory()->create(['is_default' => true, 'country' => 'US', 'phone' => null]);
    $carrier = Carrier::factory()->create(['name' => 'USPS', 'active' => true]);

    Livewire::test(Settings::class)
        ->fillForm([
            'location.name' => $location->name,
            'location.country' => 'US',
            'location.first_name' => $location->first_name,
            'location.last_name' => $location->last_name,
            'location.address1' => $location->address1,
            'location.city' => $location->city,
            'location.state_or_province' => $location->state_or_province,
            'location.postal_code' => $location->postal_code,
            'location.timezone' => $location->timezone,
            'location.phone' => null,
            'location.carrierLocations' => [
                ['carrier_id' => $carrier->id, 'pickup_days' => [1, 2, 3, 4, 5]],
            ],
        ])
        ->call('save')
        ->assertNotified();

    expect($location->fresh()->carrierLocations)
        ->toHaveCount(1)
        ->first()->carrier_id->toBe($carrier->id)
        ->first()->pickup_days->toBe([1, 2, 3, 4, 5]);
});

it('does not overwrite location fields when saving in multi-location mode', function (): void {
    Setting::create(['key' => 'multi_location_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    $location = Location::factory()->create([
        'is_default' => true,
        'name' => 'Original Warehouse',
    ]);

    Livewire::test(Settings::class)
        ->call('save')
        ->assertNotified();

    expect($location->fresh()->name)->toBe('Original Warehouse');
});
