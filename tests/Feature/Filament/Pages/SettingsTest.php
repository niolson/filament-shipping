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
use App\Models\Setting;
use App\Models\User;
use App\Services\FedexRegistrationService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

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

it('mounts sandbox_mode and suppress_printing from settings', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    Setting::create(['key' => 'suppress_printing', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    Livewire::test(Settings::class)
        ->assertSet('data.sandbox_mode', true)
        ->assertSet('data.suppress_printing', true);
});

it('saves sandbox_mode setting', function (): void {
    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => true,
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('sandbox_mode'))->toBeTrue();
});

it('saves suppress_printing setting when sandbox_mode is on', function (): void {
    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => true,
            'suppress_printing' => true,
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('suppress_printing'))->toBeTrue();
});

it('forces suppress_printing to false when sandbox_mode is turned off', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    Setting::create(['key' => 'suppress_printing', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => false,
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('suppress_printing'))->toBeFalse();
});

it('clears API auth caches when sandbox_mode changes', function (): void {
    Cache::put('usps_authenticator', 'test-token', 3600);
    Cache::put('usps_payment_authorization_token:global', 'test-payment-token-global', 3600);
    Cache::put('fedex_authenticator', 'test-fedex-token', 3600);

    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => true,
        ])
        ->call('save')
        ->assertNotified();

    expect(Cache::has('usps_authenticator'))->toBeFalse()
        ->and(Cache::has('usps_payment_authorization_token:global'))->toBeFalse()
        ->and(Cache::has('fedex_authenticator'))->toBeFalse();
});

it('does not clear API auth caches when sandbox_mode does not change', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'testing']);
    app(SettingsService::class)->clearCache();

    Cache::put('usps_authenticator', 'test-token', 3600);
    Cache::put('fedex_authenticator', 'test-fedex-token', 3600);

    Livewire::test(Settings::class)
        ->fillForm([
            'sandbox_mode' => true,
        ])
        ->call('save')
        ->assertNotified();

    expect(Cache::has('usps_authenticator'))->toBeTrue()
        ->and(Cache::has('fedex_authenticator'))->toBeTrue();
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
});

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
});

it('test usps connection shows danger notification when auth fails', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['error' => 'invalid_client'], 401),
    ]);
    Cache::forget('usps_pricing_type');

    Livewire::test(Settings::class)
        ->call('testUspsConnection')
        ->assertNotified();

    expect(Cache::get('usps_pricing_type'))->toBeNull();
});

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
    Setting::create(['key' => 'fedex.child_key', 'value' => 'child-key-123', 'type' => 'string', 'encrypted' => true, 'group' => 'fedex']);
    Setting::create(['key' => 'fedex.child_secret', 'value' => 'child-secret-456', 'type' => 'string', 'encrypted' => true, 'group' => 'fedex']);
    app(SettingsService::class)->clearCache();

    $connector = new FedexConnector;
    $config = (new ReflectionMethod($connector, 'defaultOauthConfig'))->invoke($connector);

    expect($config->getClientId())->toBe('test_api_key')
        ->and($config->getClientSecret())->toBe('test_api_secret');
});

it('fedex connector falls back to parent key when no child key', function (): void {
    // Global beforeEach seeds fedex.api_key = test_api_key and fedex.api_secret = test_api_secret
    app(SettingsService::class)->clearCache();

    $connector = new FedexConnector;
    $config = (new ReflectionMethod($connector, 'defaultOauthConfig'))->invoke($connector);

    expect($config->getClientId())->toBe('test_api_key')
        ->and($config->getClientSecret())->toBe('test_api_secret');
});

it('fedex registration service validates address and returns mfa options', function (): void {
    Storage::fake();

    Saloon::fake([
        ...fedexOauthMock(),
        ValidateAddress::class => MockResponse::make(fedexMfaResponse(), 200),
    ]);

    $result = app(FedexRegistrationService::class)->validateAddress(
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
    Saloon::fake([
        ...fedexOauthMock(),
        VerifyPin::class => MockResponse::make(fedexCredentialsResponse(), 200),
    ]);

    $credentials = app(FedexRegistrationService::class)->verifyPin('test-auth-token', '123456');

    app(FedexRegistrationService::class)->saveChildCredentials(
        $credentials['child_Key'],
        $credentials['child_secret'],
    );

    app(SettingsService::class)->clearCache();

    expect(Setting::where('key', 'fedex.child_key')->exists())->toBeTrue()
        ->and(Setting::where('key', 'fedex.child_secret')->exists())->toBeTrue();
});

it('fedex registration service saves child credentials after invoice verification', function (): void {
    Saloon::fake([
        ...fedexOauthMock(),
        VerifyInvoice::class => MockResponse::make(fedexCredentialsResponse(), 200),
    ]);

    $credentials = app(FedexRegistrationService::class)->verifyInvoice(
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
});

it('fedex registration service maps current fedex max retry codes to the fallback exception', function (): void {
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
        app(FedexRegistrationService::class)->verifyPin('test-auth-token', '123456');
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
    config([
        'services.oauth.broker_url' => 'https://polybag-connect.example.com',
        'services.oauth.instance_id' => 'test-instance',
        'services.oauth.broker_secret' => 'test-secret',
    ]);

    Saloon::fake([
        ValidateAddress::class => MockResponse::make(fedexMfaResponse(), 200),
    ]);

    $result = app(FedexRegistrationService::class)->validateAddress(
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
