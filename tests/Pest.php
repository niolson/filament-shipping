<?php

use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateRequest;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Setting;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\ShopifyFulfillmentOrderActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit', 'External', 'Browser');

pest()->browser()->timeout(15000);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function rateRequestForClient(int $clientId): RateRequest
{
    return new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
        clientId: $clientId,
    );
}

/*
|--------------------------------------------------------------------------
| Global Setup
|--------------------------------------------------------------------------
*/

uses()->beforeEach(function (): void {
    Client::factory()->default()->create();
    Location::factory()->default()->create();
    Setting::updateOrCreate(
        ['key' => 'setup_complete'],
        ['value' => '1', 'type' => 'boolean', 'group' => 'system'],
    );
})->in('Feature', 'Unit', 'External', 'Browser');

/*
|--------------------------------------------------------------------------
| Carrier / DataSource credential helpers
|--------------------------------------------------------------------------
|
| Credentials live on the CarrierAccount and DataSource models (no longer the
| settings table). These helpers create a fully-configured account/source with
| a global scope so resolveForShipment() finds it, mirroring what the legacy
| global settings seeding used to provide. Call them explicitly in tests that
| need a configured carrier or import source.
|
*/

/**
 * @param  array<string, mixed>  $secrets
 * @param  array<string, mixed>  $credentials
 */
function createUspsAccount(array $secrets = [], array $credentials = []): CarrierAccount
{
    return makeCarrierAccount('USPS', array_merge([
        'client_id' => 'test_client_id',
        'client_secret' => 'test_client_secret',
    ], $secrets), array_merge([
        'crid' => 'test_crid',
        'mid' => 'test_mid',
    ], $credentials));
}

/**
 * @param  array<string, mixed>  $secrets
 * @param  array<string, mixed>  $credentials
 */
function createFedexAccount(array $secrets = [], array $credentials = []): CarrierAccount
{
    return makeCarrierAccount('FedEx', array_merge([
        'api_key' => 'test_api_key',
        'api_secret' => 'test_api_secret',
    ], $secrets), array_merge([
        'account_number' => 'test_account',
    ], $credentials));
}

/**
 * @param  array<string, mixed>  $secrets
 * @param  array<string, mixed>  $credentials
 */
function createUpsAccount(array $secrets = [], array $credentials = []): CarrierAccount
{
    return makeCarrierAccount('UPS', array_merge([
        'client_id' => 'test_client_id',
        'client_secret' => 'test_client_secret',
    ], $secrets), array_merge([
        'account_number' => 'test_account',
    ], $credentials));
}

/**
 * @param  array<string, mixed>  $secrets
 * @param  array<string, mixed>  $credentials
 */
function makeCarrierAccount(string $carrierName, array $secrets, array $credentials): CarrierAccount
{
    $carrier = Carrier::firstOrCreate(['name' => $carrierName]);

    $account = CarrierAccount::create([
        'carrier_id' => $carrier->id,
        'name' => "{$carrierName} Test Account",
        'active' => true,
        'credentials' => $credentials,
        'secret_credentials' => $secrets,
    ]);

    CarrierAccountScope::create([
        'carrier_account_id' => $account->id,
        'location_id' => null,
        'client_id' => null,
        'rate_shop' => false,
    ]);

    return $account;
}

/**
 * @param  array<string, mixed>  $settings  Non-secret settings (e.g. shop_domain, channel_name)
 * @param  array<string, mixed>  $secrets  Encrypted settings (e.g. client_id, client_secret, access_token)
 */
function createShopifyDataSource(array $settings = [], array $secrets = []): DataSource
{
    return DataSource::create([
        'name' => 'Shopify Test',
        'source_type' => ShopifySource::class,
        'active' => true,
        'settings' => array_merge([
            'channel_name' => 'Shopify',
            'shop_domain' => 'test-shop.myshopify.com',
        ], $settings),
        'secret_settings' => array_merge([
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
        ], $secrets),
    ]);
}

/**
 * Shopify's reply to the access-scopes query.
 *
 * Anything that verifies scopes against the live token — location
 * synchronization, fulfillment-order activation — issues this request before
 * its real work, so a faked sequence has to answer it first.
 *
 * @param  array<int, string>|null  $scopes  Defaults to every required scope.
 */
function shopifyAccessScopesResponse(?array $scopes = null): MockResponse
{
    $scopes ??= ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES;

    return MockResponse::make([
        'data' => ['currentAppInstallation' => [
            'accessScopes' => array_map(fn (string $handle): array => ['handle' => $handle], $scopes),
        ]],
    ]);
}

/*
|--------------------------------------------------------------------------
| SP-API schema validation
|--------------------------------------------------------------------------
|
| Asserting a request body against a hand-written golden array only proves the
| body matches what we thought we were building. If we built the wrong shape and
| froze that same wrong shape into the test, both agree and the live API 400s.
| These helpers check the body against Amazon's published spec instead.
|
| Schemas are vendored under tests/Fixtures/Schemas — see the README there.
|
*/

/**
 * Validate a request body against a definition in a vendored SP-API schema.
 *
 * @param  array<string, mixed>  $body
 * @param  string  $definition  A key under the document's "definitions", e.g. "ConfirmShipmentRequest"
 *
 * @throws AssertionFailedError
 */
function assertMatchesSpApiSchema(array $body, string $definition, string $document = 'ordersV0'): void
{
    $path = __DIR__.'/Fixtures/Schemas/'.$document.'.json';

    if (! is_file($path)) {
        Assert::fail("Vendored SP-API schema [{$document}.json] is missing from tests/Fixtures/Schemas.");
    }

    $validator = new Validator;

    // The validator wants stdClass for "type": "object"; a PHP associative
    // array decodes as an array and fails every object constraint. It also
    // takes the value by reference, so it has to be a variable.
    $decoded = json_decode(json_encode($body));

    $validator->validate(
        $decoded,
        (object) ['$ref' => 'file://'.$path.'#/definitions/'.$definition],
    );

    if (! $validator->isValid()) {
        $failures = array_map(
            fn (array $error): string => '  - '.($error['property'] !== '' ? $error['property'].': ' : '').$error['message'],
            $validator->getErrors(),
        );

        Assert::fail(
            "Request body does not conform to {$document}#/definitions/{$definition}:\n"
            .implode("\n", $failures)
            ."\n\nBody sent:\n".json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    // Record the pass, so a test whose only check is this helper isn't reported
    // as risky for performing no assertions.
    Assert::assertTrue($validator->isValid());
}
