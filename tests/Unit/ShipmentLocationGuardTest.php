<?php

use App\Models\Location;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ShipmentLocationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it permits an operator assigned to the shipment location', function (): void {
    $location = Location::factory()->create();
    $shipment = Shipment::factory()->create(['location_id' => $location]);
    $operator = User::factory()->create(['location_id' => $location]);

    expect(app(ShipmentLocationGuard::class)->errorFor($shipment, $operator))->toBeNull();
});

test('it explains an operator location mismatch', function (): void {
    $shipmentLocation = Location::factory()->create(['name' => 'East Warehouse']);
    $operatorLocation = Location::factory()->create(['name' => 'West Warehouse']);
    $shipment = Shipment::factory()->create(['location_id' => $shipmentLocation]);
    $operator = User::factory()->create(['location_id' => $operatorLocation]);

    expect(app(ShipmentLocationGuard::class)->errorFor($shipment, $operator))
        ->toContain('East Warehouse')
        ->toContain('West Warehouse');
});

test('it rejects shipments assigned to an inactive location', function (): void {
    $location = Location::factory()->create(['name' => 'Closed Warehouse', 'active' => false]);
    $shipment = Shipment::factory()->create(['location_id' => $location]);

    expect(app(ShipmentLocationGuard::class)->errorFor($shipment, User::factory()->create()))
        ->toContain('inactive location Closed Warehouse');
});
