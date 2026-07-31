<?php

use App\Enums\Deliverability;
use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Models\Location;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SettingsService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('displays status column in shipment table', function (): void {
    $shipped = Shipment::factory()->shipped()->create();
    $notShipped = Shipment::factory()->create(['status' => ShipmentStatus::Open]);

    Livewire::test(ListShipments::class)
        ->assertCanSeeTableRecords([$shipped, $notShipped]);
});

it('filters shipments by status', function (): void {
    $shipped = Shipment::factory()->shipped()->create();
    $notShipped = Shipment::factory()->create(['status' => ShipmentStatus::Open]);

    Livewire::test(ListShipments::class)
        ->filterTable('status', ShipmentStatus::Shipped->value)
        ->assertCanSeeTableRecords([$shipped])
        ->assertCanNotSeeTableRecords([$notShipped]);

    Livewire::test(ListShipments::class)
        ->filterTable('status', ShipmentStatus::Open->value)
        ->assertCanSeeTableRecords([$notShipped])
        ->assertCanNotSeeTableRecords([$shipped]);
});

it('filters shipments by status and deliverability tab groups together', function (): void {
    $matchingShipment = Shipment::factory()->create([
        'status' => ShipmentStatus::Open,
        'deliverability' => Deliverability::Yes,
    ]);
    $wrongStatusShipment = Shipment::factory()->create([
        'status' => ShipmentStatus::Shipped,
        'deliverability' => Deliverability::Yes,
    ]);
    $wrongDeliverabilityShipment = Shipment::factory()->create([
        'status' => ShipmentStatus::Open,
        'deliverability' => Deliverability::No,
    ]);

    Livewire::test(ListShipments::class)
        ->set('activeStatusTab', ShipmentStatus::Open->value)
        ->set('activeDeliverabilityTab', Deliverability::Yes->value)
        ->assertCanSeeTableRecords([$matchingShipment])
        ->assertCanNotSeeTableRecords([$wrongStatusShipment, $wrongDeliverabilityShipment]);
});

it('shows and filters shipment locations in multi-location mode', function (): void {
    Setting::create(['key' => 'multi_location_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();
    $east = Location::factory()->create(['name' => 'East']);
    $west = Location::factory()->create(['name' => 'West']);
    $eastShipment = Shipment::factory()->create(['location_id' => $east]);
    $westShipment = Shipment::factory()->create(['location_id' => $west]);

    Livewire::test(ListShipments::class)
        ->assertTableColumnVisible('location.name')
        ->filterTable('location', $east->id)
        ->assertCanSeeTableRecords([$eastShipment])
        ->assertCanNotSeeTableRecords([$westShipment]);
});
