<?php

use App\Models\DailyShippingStat;
use App\Models\Location;
use App\Models\Package;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Give the fixtures the `ship_date` production would hold.
 *
 * SQLite has no date type, so a date-cast column round-trips as
 * "Y-m-d H:i:s" — a time component MySQL's DATE column never has. The
 * aggregation compares `ship_date` against plain Y-m-d bounds, which then
 * matches nothing under SQLite for reasons that exist nowhere but the test
 * database.
 */
function normalizeAggregationShipDates(): void
{
    DB::table('packages')->update([
        'ship_date' => Carbon::today(Location::timezone())->toDateString(),
    ]);
}

it('counts only the packages that reported a cost', function (): void {
    Package::factory()->shipped()->count(3)->create([
        'carrier' => 'USPS',
        'service' => 'Ground',
        'cost' => 10.00,
    ]);

    // Postage bought where the seller reports no price: the package is real and
    // shipped, but nothing priced it.
    Package::factory()->shipped()->count(2)->create([
        'carrier' => 'USPS',
        'service' => 'Ground',
        'cost' => null,
    ]);

    normalizeAggregationShipDates();

    $this->artisan('stats:aggregate', ['--today' => true])->assertSuccessful();

    expect((int) DailyShippingStat::sum('package_count'))->toBe(5)
        ->and((int) DailyShippingStat::sum('costed_package_count'))->toBe(3)
        ->and((float) DailyShippingStat::sum('total_cost'))->toBe(30.00);
});

it('records every package as costed when all of them priced', function (): void {
    Package::factory()->shipped()->count(4)->create(['cost' => 7.50]);

    normalizeAggregationShipDates();

    $this->artisan('stats:aggregate', ['--today' => true])->assertSuccessful();

    expect((int) DailyShippingStat::sum('costed_package_count'))
        ->toBe((int) DailyShippingStat::sum('package_count'));
});

it('backfills the costed count onto rows aggregated before the column existed', function (): void {
    Package::factory()->shipped()->count(3)->create([
        'carrier' => 'USPS',
        'service' => 'Ground',
        'cost' => 10.00,
    ]);

    Package::factory()->shipped()->count(2)->create([
        'carrier' => 'USPS',
        'service' => 'Ground',
        'cost' => null,
    ]);

    normalizeAggregationShipDates();

    $this->artisan('stats:aggregate', ['--today' => true])->assertSuccessful();

    // Rows as they looked before the column: counted, never costed.
    DailyShippingStat::query()->update(['costed_package_count' => null]);

    $migration = require database_path('migrations/2026_09_05_130100_backfill_costed_package_count.php');
    $migration->up();

    expect((int) DailyShippingStat::sum('costed_package_count'))->toBe(3);
});

it('leaves a rollup row uncomputed when no packages match it', function (): void {
    // A row left behind by a package that was deleted or re-dated. Zero would
    // claim every package in it was unpriced; null keeps saying "unknown".
    DailyShippingStat::create([
        'date' => today()->subYear()->toDateString(),
        'carrier' => 'USPS',
        'package_count' => 4,
        'total_cost' => 40.00,
        'total_weight' => 8.00,
    ]);

    $migration = require database_path('migrations/2026_09_05_130100_backfill_costed_package_count.php');
    $migration->up();

    expect(DailyShippingStat::first()->costed_package_count)->toBeNull();
});

it('keeps an empty service group apart from a null one when backfilling', function (): void {
    // GROUP BY keeps '' and NULL apart, so these are two rollup rows differing
    // in nothing but `service`. One shipment so every other grouping key is
    // identical by construction.
    $shipment = Shipment::factory()->create();

    Package::factory()->shipped()->count(3)->create([
        'shipment_id' => $shipment->id,
        'carrier' => 'USPS',
        'service' => '',
        'cost' => 10.00,
    ]);

    Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'carrier' => 'USPS',
        'service' => null,
        'cost' => null,
    ]);

    normalizeAggregationShipDates();

    $this->artisan('stats:aggregate', ['--today' => true])->assertSuccessful();

    expect(DailyShippingStat::count())->toBe(2);

    DailyShippingStat::query()->update(['costed_package_count' => null]);

    $migration = require database_path('migrations/2026_09_05_130100_backfill_costed_package_count.php');
    $migration->up();

    $empty = DailyShippingStat::where('service', '')->sole();
    $null = DailyShippingStat::whereNull('service')->sole();

    expect($empty->costed_package_count)->toBe(3)
        ->and($null->costed_package_count)->toBe(0);

    // The failure mode this guards: each row collecting the other's packages.
    DailyShippingStat::each(function (DailyShippingStat $stat): void {
        expect($stat->costed_package_count)->toBeLessThanOrEqual($stat->package_count);
    });
});
