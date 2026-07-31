<?php

use App\Models\DataSource;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Services\ShipmentImport\ImportReferenceResolver;
use App\Services\ShipmentImport\ShipmentItemImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an empty authoritative source result does not delete existing shipment items', function (): void {
    [$shipment, $source] = authoritativeShipmentWithItems(3);

    shipmentItemImporter()->import($shipment, collect(), $source);

    expect($shipment->shipmentItems()->count())->toBe(3);
});

test('unresolvable authoritative source rows do not delete existing shipment items', function (): void {
    [$shipment, $source] = authoritativeShipmentWithItems(2);

    shipmentItemImporter()->import($shipment, collect([
        ['name' => 'Missing identity', 'quantity' => 1],
    ]), $source);

    expect($shipment->shipmentItems()->count())->toBe(2);
});

test('a resolved authoritative source snapshot removes stale unpacked items', function (): void {
    [$shipment, $source] = authoritativeShipmentWithItems(2);
    $retained = $shipment->shipmentItems()->with('product')->firstOrFail();

    shipmentItemImporter()->import($shipment, collect([
        ['sku' => $retained->product->sku, 'name' => $retained->product->name, 'quantity' => 4],
    ]), $source);

    expect($shipment->shipmentItems()->count())->toBe(1)
        ->and($retained->refresh()->quantity)->toBe(4);
});

test('a partially unresolvable authoritative snapshot preserves potentially unmatched items', function (): void {
    [$shipment, $source] = authoritativeShipmentWithItems(2);
    $retained = $shipment->shipmentItems()->with('product')->firstOrFail();

    shipmentItemImporter()->import($shipment, collect([
        ['sku' => $retained->product->sku, 'name' => $retained->product->name, 'quantity' => 4],
        ['name' => 'Missing identity', 'quantity' => 1],
    ]), $source);

    expect($shipment->shipmentItems()->count())->toBe(2)
        ->and($retained->refresh()->quantity)->toBe(4);
});

/** @return array{Shipment, DataSource} */
function authoritativeShipmentWithItems(int $count): array
{
    $source = DataSource::factory()->create([
        'settings' => ['authoritative_shipment_items' => true],
    ]);
    $shipment = Shipment::factory()->create(['data_source_id' => $source]);

    Product::factory()->count($count)->create()->each(function (Product $product) use ($shipment): void {
        ShipmentItem::factory()->create([
            'shipment_id' => $shipment,
            'product_id' => $product,
        ]);
    });

    return [$shipment, $source];
}

function shipmentItemImporter(): ShipmentItemImporter
{
    return new ShipmentItemImporter(app(ImportReferenceResolver::class));
}
