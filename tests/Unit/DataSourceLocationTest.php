<?php

use App\Models\DataSource;
use App\Models\DataSourceLocation;
use App\Models\Location;
use App\Models\Package;
use App\Models\Shipment;
use Illuminate\Database\UniqueConstraintViolationException;

it('derives mapped ignored and unmapped states', function (): void {
    $unmapped = DataSourceLocation::factory()->create();
    $mapped = DataSourceLocation::factory()->create(['location_id' => Location::factory()]);
    $ignored = DataSourceLocation::factory()->create(['ignored_at' => now()]);

    expect($unmapped->isUnmapped())->toBeTrue()
        ->and($mapped->isMapped())->toBeTrue()
        ->and($ignored->isIgnored())->toBeTrue();
});

it('scopes external identifiers to a data source', function (): void {
    $sourceA = DataSource::factory()->create();
    $sourceB = DataSource::factory()->create();

    DataSourceLocation::factory()->create(['data_source_id' => $sourceA, 'external_id' => 'warehouse-1']);
    DataSourceLocation::factory()->create(['data_source_id' => $sourceB, 'external_id' => 'warehouse-1']);

    expect(fn () => DataSourceLocation::factory()->create([
        'data_source_id' => $sourceA,
        'external_id' => 'warehouse-1',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('relates a source location and polybag location to shipments', function (): void {
    $sourceLocation = DataSourceLocation::factory()->create([
        'location_id' => $location = Location::factory()->create(),
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $sourceLocation->data_source_id,
        'data_source_location_id' => $sourceLocation,
        'location_id' => $location,
    ]);

    expect($shipment->location->is($location))->toBeTrue()
        ->and($shipment->dataSourceLocation->is($sourceLocation))->toBeTrue()
        ->and($sourceLocation->shipments->first()->is($shipment))->toBeTrue();
});

it('nulls shipment and mapping references when related locations are deleted', function (): void {
    $location = Location::factory()->create();
    $sourceLocation = DataSourceLocation::factory()->create(['location_id' => $location]);
    $shipment = Shipment::factory()->create([
        'location_id' => $location,
        'data_source_location_id' => $sourceLocation,
    ]);

    $location->delete();
    $sourceLocation->delete();

    expect($shipment->refresh()->location_id)->toBeNull()
        ->and($shipment->data_source_location_id)->toBeNull();
});

it('backfills open unpacked shipments when a source mapping changes', function (): void {
    $oldLocation = Location::factory()->create();
    $newLocation = Location::factory()->create();
    $sourceLocation = DataSourceLocation::factory()->create(['location_id' => $oldLocation]);
    $unpacked = Shipment::factory()->create([
        'data_source_location_id' => $sourceLocation,
        'location_id' => $oldLocation,
        'status' => 'open',
    ]);
    $packed = Shipment::factory()->create([
        'data_source_location_id' => $sourceLocation,
        'location_id' => $oldLocation,
        'status' => 'open',
    ]);
    Package::factory()->create(['shipment_id' => $packed, 'location_id' => $oldLocation]);

    $sourceLocation->update(['location_id' => $newLocation->id]);

    expect($unpacked->refresh()->location_id)->toBe($newLocation->id)
        ->and($packed->refresh()->location_id)->toBe($oldLocation->id);
});
