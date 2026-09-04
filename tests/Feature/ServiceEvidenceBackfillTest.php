<?php

use App\Enums\PostageSource;
use App\Enums\ServiceEvidence;
use App\Models\DataSource;
use App\Models\Package;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Support\Facades\DB;

function runServiceEvidenceBackfill(): void
{
    $migration = require database_path('migrations/2026_09_03_210100_backfill_package_service_evidence.php');

    $migration->up();
}

/**
 * Packages predating the evidence columns, written straight through the query
 * builder so the model's own invariants don't stamp them on the way in.
 *
 * @param  array<string, mixed>  $attributes
 */
function legacyPackage(array $attributes): Package
{
    $package = Package::factory()->shipped()->create();

    DB::table('packages')
        ->where('id', $package->id)
        ->update($attributes + ['service_evidence' => 'unknown']);

    return $package->refresh();
}

it('records a legacy direct purchase as a confirmed service', function (): void {
    $package = legacyPackage([
        'service' => 'USPS_GROUND_ADVANTAGE',
        'postage_source' => PostageSource::CarrierAccount->value,
    ]);

    runServiceEvidenceBackfill();
    runServiceEvidenceBackfill();

    expect($package->refresh()->service_evidence)->toBe(ServiceEvidence::Confirmed)
        ->and($package->service)->toBe('USPS_GROUND_ADVANTAGE')
        ->and($package->requested_service)->toBeNull();
});

it('leaves a package with no service unknown', function (array $attributes): void {
    $package = legacyPackage($attributes);

    runServiceEvidenceBackfill();

    expect($package->refresh()->service_evidence)->toBe(ServiceEvidence::Unknown)
        ->and($package->confirmedService())->toBeNull();
})->with([
    'shipped without one' => [['service' => null, 'postage_source' => PostageSource::CarrierAccount->value]],
    'shipped with an empty one' => [['service' => '', 'postage_source' => PostageSource::CarrierAccount->value]],
    'never shipped at all' => [['service' => null, 'status' => 'unshipped', 'postage_source' => null]],
]);

it('demotes a Shopify service to the preference it always was', function (): void {
    $package = legacyPackage([
        'service' => 'USPS Ground Advantage',
        'postage_source' => PostageSource::PostageDataSource->value,
        'postage_data_source_id' => DataSource::factory()->shopify()->create()->id,
        'carrier_account_id' => null,
    ]);

    runServiceEvidenceBackfill();
    runServiceEvidenceBackfill();

    // Shopify never reported a purchased service, so nothing on this row was
    // ever a confirmation — and nothing is published outward from it.
    expect($package->refresh()->service)->toBeNull()
        ->and($package->requested_service)->toBe('USPS Ground Advantage')
        ->and($package->service_evidence)->toBe(ServiceEvidence::Unknown)
        ->and($package->confirmedService())->toBeNull();
});

it('leaves evidence already recorded alone', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'Priority Mail',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'tracking_number_prefix',
        'service_ruleset_version' => '2026.09.1',
    ]);

    runServiceEvidenceBackfill();

    expect($package->refresh()->service_evidence)->toBe(ServiceEvidence::Inferred)
        ->and($package->service_inference_method)->toBe('tracking_number_prefix');
});

it('keeps a sales-channel service that its source actually reported', function (): void {
    $package = legacyPackage([
        'service' => 'FEDEX_GROUND',
        'postage_source' => PostageSource::PostageDataSource->value,
        'postage_data_source_id' => DataSource::factory()->create([
            'source_type' => AmazonSource::class,
        ])->id,
        'carrier_account_id' => null,
    ]);

    runServiceEvidenceBackfill();

    // Not every postage data source is blind. Amazon Buy Shipping names the
    // service it sold, so demoting it would throw away a real confirmation.
    expect($package->refresh()->service)->toBe('FEDEX_GROUND')
        ->and($package->requested_service)->toBeNull()
        ->and($package->service_evidence)->toBe(ServiceEvidence::Confirmed);
});
