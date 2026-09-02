<?php

use App\Enums\PostageSource;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\Package;
use App\Services\ManifestService;
use Illuminate\Support\Facades\DB;

function runPostageSourceBackfill(): void
{
    $migration = require database_path('migrations/2026_09_02_193821_backfill_package_postage_source.php');

    $migration->up();
}

it('backfills legacy shipped packages as direct purchases without changing their pointers', function (): void {
    $carrierAccount = CarrierAccount::factory()->create();
    $legacyPackage = Package::factory()->shipped()->create([
        'carrier_account_id' => $carrierAccount->id,
        'carrier' => 'USPS',
        'tracking_number' => '9400111',
        'postage_source' => null,
    ]);
    $legacyPackageWithoutAccount = Package::factory()->shipped()->create([
        'carrier_account_id' => null,
        'postage_source' => null,
    ]);
    $unshippedPackage = Package::factory()->create();
    $alreadyAttributedPackage = Package::factory()->shipped()->create([
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => DataSource::factory(),
        'carrier_account_id' => null,
    ]);

    runPostageSourceBackfill();
    runPostageSourceBackfill();

    expect($legacyPackage->refresh()->postage_source)->toBe(PostageSource::CarrierAccount)
        ->and($legacyPackage->carrier_account_id)->toBe($carrierAccount->id)
        ->and($legacyPackage->postage_data_source_id)->toBeNull()
        ->and($legacyPackageWithoutAccount->refresh()->postage_source)->toBe(PostageSource::CarrierAccount)
        ->and($legacyPackageWithoutAccount->carrier_account_id)->toBeNull()
        ->and($legacyPackageWithoutAccount->postage_data_source_id)->toBeNull()
        ->and($unshippedPackage->refresh()->postage_source)->toBeNull()
        ->and($alreadyAttributedPackage->refresh()->postage_source)->toBe(PostageSource::PostageDataSource)
        ->and(app(ManifestService::class)->getUnmanifestedPackages()['USPS']->pluck('id')->all())
        ->toContain($legacyPackage->id);
});

it('fails without backfilling anything when a legacy package looks Shopify-bought', function (array $attributes): void {
    $directPackage = Package::factory()->shipped()->create(['postage_source' => null]);
    $shopifyPackage = Package::factory()->shipped()->create($attributes + ['postage_source' => null]);

    expect(fn () => runPostageSourceBackfill())
        ->toThrow(RuntimeException::class, "Package {$shopifyPackage->id}");

    expect($directPackage->refresh()->postage_source)->toBeNull()
        ->and($shopifyPackage->refresh()->postage_source)->toBeNull();
})->with([
    'legacy carrier value' => [['carrier' => 'Shopify']],
    'legacy Shopify label metadata' => [[
        'carrier' => 'USPS',
        'metadata' => ['shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/1'],
    ]],
]);

it('fails loudly for malformed legacy metadata that mentions a Shopify label', function (): void {
    $directPackage = Package::factory()->shipped()->create(['postage_source' => null]);
    $shopifyPackage = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'postage_source' => null,
    ]);

    DB::table('packages')
        ->where('id', $shopifyPackage->id)
        ->update(['metadata' => '{"shopify_shipping_label_id":']);

    expect(fn () => runPostageSourceBackfill())
        ->toThrow(RuntimeException::class, "Package {$shopifyPackage->id}");

    expect($directPackage->refresh()->postage_source)->toBeNull()
        ->and($shopifyPackage->refresh()->postage_source)->toBeNull();
});

it('refuses to create contradictory provenance from a sales-channel pointer', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'postage_source' => null,
        'postage_data_source_id' => DataSource::factory(),
    ]);

    expect(fn () => runPostageSourceBackfill())
        ->toThrow(RuntimeException::class, "Package {$package->id}");

    expect($package->refresh()->postage_source)->toBeNull();
});
