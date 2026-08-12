<?php

use App\Enums\LabelReferenceSource;
use App\Models\Client;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Shipment;
use App\Services\LabelReferenceResolver;
use App\Services\SettingsService;

beforeEach(function (): void {
    $this->resolver = app(LabelReferenceResolver::class);
});

function setInstanceLabelReferenceSource(LabelReferenceSource $source): void
{
    Setting::updateOrCreate(
        ['key' => 'label_reference_source'],
        ['value' => $source->value, 'type' => 'string', 'group' => 'general'],
    );

    app(SettingsService::class)->clearCache();
}

/**
 * @param  array<string, mixed>  $shipmentAttributes
 */
function packageForClient(Client $client, array $shipmentAttributes = []): Package
{
    $shipment = Shipment::factory()->create([
        'client_id' => $client->id,
        ...$shipmentAttributes,
    ]);

    return Package::factory()->for($shipment)->create();
}

it('falls back to the shipment reference when neither the instance nor the client has chosen a source', function (): void {
    $client = Client::factory()->create(['label_reference_source' => null]);
    $package = packageForClient($client, ['shipment_reference' => 'ORD-10042']);

    expect($this->resolver->forPackage($package))->toBe(['ORD-10042']);
});

it('follows the instance setting for clients that have not chosen a source', function (): void {
    setInstanceLabelReferenceSource(LabelReferenceSource::PackageId);

    $client = Client::factory()->create(['label_reference_source' => null]);
    $package = packageForClient($client, ['shipment_reference' => 'ORD-10042']);

    expect($this->resolver->forPackage($package))->toBe([(string) $package->id]);
});

it('lets a client override the instance setting', function (): void {
    setInstanceLabelReferenceSource(LabelReferenceSource::None);

    $client = Client::factory()->create(['label_reference_source' => LabelReferenceSource::ShipmentReference]);
    $package = packageForClient($client, ['shipment_reference' => 'ORD-10042']);

    expect($this->resolver->forPackage($package))->toBe(['ORD-10042']);
});

it('ignores an instance setting that no longer names a known source', function (): void {
    Setting::updateOrCreate(
        ['key' => 'label_reference_source'],
        ['value' => 'retired_source', 'type' => 'string', 'group' => 'general'],
    );
    app(SettingsService::class)->clearCache();

    $client = Client::factory()->create(['label_reference_source' => null]);
    $package = packageForClient($client, ['shipment_reference' => 'ORD-10042']);

    expect($this->resolver->forPackage($package))->toBe(['ORD-10042']);
});

it('resolves each label reference source', function (LabelReferenceSource $source, callable $expected): void {
    $client = Client::factory()->create(['label_reference_source' => $source]);
    $package = packageForClient($client, ['shipment_reference' => 'ORD-10042']);

    expect($this->resolver->forPackage($package))->toBe($expected($package));
})->with([
    'shipment reference' => [LabelReferenceSource::ShipmentReference, fn (Package $package): array => ['ORD-10042']],
    'shipment id' => [LabelReferenceSource::ShipmentId, fn (Package $package): array => [(string) $package->shipment_id]],
    'package id' => [LabelReferenceSource::PackageId, fn (Package $package): array => [(string) $package->id]],
    'none' => [LabelReferenceSource::None, fn (Package $package): array => []],
]);

it('returns no reference when the shipment has no reference to print', function (): void {
    $client = Client::factory()->create(['label_reference_source' => LabelReferenceSource::ShipmentReference]);
    $package = packageForClient($client, ['shipment_reference' => null]);

    expect($this->resolver->forPackage($package))->toBe([]);
});

it('collapses whitespace and drops control characters carriers would mangle', function (): void {
    $client = Client::factory()->create(['label_reference_source' => LabelReferenceSource::ShipmentReference]);
    $package = packageForClient($client, ['shipment_reference' => "  ORD\t 10042\n"]);

    expect($this->resolver->forPackage($package))->toBe(['ORD 10042']);
});
