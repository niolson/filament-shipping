<?php

use App\Models\Carrier;
use App\Models\CarrierAlias;
use App\Services\Carriers\ShopifyAdapter;

function runShopifyPickupCutoffBackfill(): void
{
    $migration = require database_path('migrations/2026_09_03_120000_set_shopify_pickup_cutoff_hour.php');

    $migration->up();
}

it('carries the interim Shopify cutoff onto the carrier row of an existing install', function (): void {
    $shopify = Carrier::factory()->create([
        'name' => ShopifyAdapter::CARRIER_NAME,
        'pickup_cutoff_hour' => null,
    ]);

    runShopifyPickupCutoffBackfill();
    runShopifyPickupCutoffBackfill();

    expect($shopify->refresh()->pickup_cutoff_hour)->toBe(20);
});

it('reaches a Shopify carrier an operator renamed, through its alias', function (): void {
    $shopify = Carrier::factory()->create([
        'name' => 'Shopify Shipping (USPS CeC)',
        'pickup_cutoff_hour' => null,
    ]);
    CarrierAlias::create(['carrier_id' => $shopify->id, 'alias' => ShopifyAdapter::CARRIER_NAME]);

    runShopifyPickupCutoffBackfill();

    expect($shopify->refresh()->pickup_cutoff_hour)->toBe(20);
});

it('leaves a cutoff that is already set alone', function (): void {
    $shopify = Carrier::factory()->create([
        'name' => ShopifyAdapter::CARRIER_NAME,
        'pickup_cutoff_hour' => 17,
    ]);

    runShopifyPickupCutoffBackfill();

    expect($shopify->refresh()->pickup_cutoff_hour)->toBe(17);
});

it('touches no carrier but Shopify', function (): void {
    $fedex = Carrier::factory()->fedex()->create(['pickup_cutoff_hour' => null]);
    $usps = Carrier::factory()->usps()->create();

    runShopifyPickupCutoffBackfill();

    expect($fedex->refresh()->pickup_cutoff_hour)->toBeNull()
        ->and($usps->refresh()->pickup_cutoff_hour)->toBe(20);
});

it('does nothing on an install that never enabled Shopify Shipping', function (): void {
    expect(Carrier::query()->where('name', ShopifyAdapter::CARRIER_NAME)->exists())->toBeFalse();

    runShopifyPickupCutoffBackfill();

    expect(Carrier::query()->whereNotNull('pickup_cutoff_hour')->exists())->toBeFalse();
});
