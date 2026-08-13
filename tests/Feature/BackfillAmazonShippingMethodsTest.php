<?php

use App\Models\DataSource;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Services\ShipmentImport\Sources\AmazonSource;

function amazonShipmentWithPackages(DataSource $source, ?string $shippingService, string $reference): Shipment
{
    return Shipment::factory()->create([
        'data_source_id' => $source->id,
        'source_record_id' => $reference,
        'shipment_reference' => $reference,
        'shipping_method_id' => null,
        'metadata' => [
            'amazon_order_id' => $reference,
            'amazon_packages' => [array_filter([
                'carrier' => 'USPS',
                'shippingService' => $shippingService,
                'trackingNumber' => '9400100000000000000000',
            ])],
        ],
    ]);
}

beforeEach(function (): void {
    $this->dataSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'name' => 'Amazon',
        'settings' => ['channel_name' => 'Amazon', 'marketplace_id' => 'ATVPDKIKX0DER'],
    ]);
});

it('maps aliased Amazon package services onto shipments missing a shipping method', function (): void {
    $method = ShippingMethod::factory()->create(['name' => 'USPS Ground Advantage']);
    ShippingMethodAlias::create(['reference' => 'First-Class Mail', 'shipping_method_id' => $method->id]);

    $mapped = amazonShipmentWithPackages($this->dataSource, 'First-Class Mail', 'AMZ-1');
    $unmapped = amazonShipmentWithPackages($this->dataSource, 'Priority Mail', 'AMZ-2');

    $this->artisan('amazon:backfill-shipping-methods')->assertSuccessful();

    expect($mapped->refresh()->shipping_method_id)->toBe($method->id)
        ->and($mapped->shipping_method_reference)->toBe('First-Class Mail')
        ->and($unmapped->refresh()->shipping_method_id)->toBeNull()
        ->and($unmapped->shipping_method_reference)->toBe('Priority Mail');
});

it('leaves shipments that already have a shipping method untouched', function (): void {
    $existing = ShippingMethod::factory()->create(['name' => 'Existing Method']);
    $other = ShippingMethod::factory()->create(['name' => 'Other Method']);
    ShippingMethodAlias::create(['reference' => 'First-Class Mail', 'shipping_method_id' => $other->id]);

    $shipment = amazonShipmentWithPackages($this->dataSource, 'First-Class Mail', 'AMZ-3');
    $shipment->update(['shipping_method_id' => $existing->id]);

    $this->artisan('amazon:backfill-shipping-methods')->assertSuccessful();

    expect($shipment->refresh()->shipping_method_id)->toBe($existing->id)
        ->and($shipment->shipping_method_reference)->toBeNull();
});

it('skips shipments whose Amazon packages carry no shipping service', function (): void {
    $shipment = amazonShipmentWithPackages($this->dataSource, null, 'AMZ-4');

    $this->artisan('amazon:backfill-shipping-methods')
        ->expectsOutputToContain('1 shipments had no shippingService')
        ->assertSuccessful();

    expect($shipment->refresh()->shipping_method_reference)->toBeNull();
});

it('writes nothing on a dry run', function (): void {
    $method = ShippingMethod::factory()->create(['name' => 'USPS Ground Advantage']);
    ShippingMethodAlias::create(['reference' => 'First-Class Mail', 'shipping_method_id' => $method->id]);

    $shipment = amazonShipmentWithPackages($this->dataSource, 'First-Class Mail', 'AMZ-5');

    $this->artisan('amazon:backfill-shipping-methods', ['--dry-run' => true])->assertSuccessful();

    expect($shipment->refresh()->shipping_method_id)->toBeNull()
        ->and($shipment->shipping_method_reference)->toBeNull();
});

it('ignores shipments from other data sources', function (): void {
    $method = ShippingMethod::factory()->create(['name' => 'USPS Ground Advantage']);
    ShippingMethodAlias::create(['reference' => 'First-Class Mail', 'shipping_method_id' => $method->id]);

    $otherSource = DataSource::factory()->create(['source_type' => AmazonSource::class, 'name' => 'Amazon EU']);
    $shipment = amazonShipmentWithPackages($otherSource, 'First-Class Mail', 'AMZ-6');

    $this->artisan('amazon:backfill-shipping-methods', ['--data-source' => $this->dataSource->id])->assertSuccessful();

    expect($shipment->refresh()->shipping_method_id)->toBeNull();
});
