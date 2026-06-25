<?php

use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Setting;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        'driver' => ShopifySource::class,
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
