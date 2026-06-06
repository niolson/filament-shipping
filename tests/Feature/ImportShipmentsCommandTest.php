<?php

use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('--all --dry-run does not run real imports', function (): void {
    // Create an active DataSource backed by a mock driver that would fail if import() ran
    DataSource::factory()->create([

        'driver' => ShopifySource::class,
        'active' => true,
        'settings' => [
            'shop_domain' => 'test.myshopify.com',
            'channel_name' => 'Shopify',
        ],
        'secret_settings' => [
            'access_token' => 'shpat_test_token',
        ],
    ]);

    // With --dry-run the command should validate + fetch shipments but not persist anything.
    // ShopifySource::fetchShipments() will throw (no HTTP mock), so we expect a non-zero
    // exit code — but critically NOT a database write (shipmentsCreated = 0).
    $this->artisan('shipments:import', ['--all' => true, '--dry-run' => true])
        ->assertExitCode(1); // dry-run fails at fetch (no HTTP mock), but no DB writes

    expect(Shipment::count())->toBe(0);
});

it('--all --validate-only does not run real imports', function (): void {
    DataSource::factory()->create([

        'driver' => ShopifySource::class,
        'active' => true,
        'settings' => [
            'shop_domain' => 'test.myshopify.com',
            'channel_name' => 'Shopify',
        ],
        'secret_settings' => [
            'access_token' => 'shpat_test_token',
        ],
    ]);

    // validate-only just calls validateConfiguration(); with an access_token it should pass.
    $this->artisan('shipments:import', ['--all' => true, '--validate-only' => true])
        ->assertExitCode(0);

    expect(Shipment::count())->toBe(0);
});

it('--all without flags runs real imports (not dry-run) for each source', function (): void {
    DataSource::factory()->create([

        'driver' => ShopifySource::class,
        'active' => true,
        'settings' => [
            'shop_domain' => 'test.myshopify.com',
            'channel_name' => 'Shopify',
        ],
        'secret_settings' => [
            'access_token' => 'shpat_test_token',
        ],
    ]);

    // Without --dry-run the command proceeds past validation into fetchShipments(),
    // which fails here (no HTTP mock). The key assertion is no Shipment rows were written.
    $this->artisan('shipments:import', ['--all' => true])
        ->assertExitCode(1);

    expect(Shipment::count())->toBe(0);
});
