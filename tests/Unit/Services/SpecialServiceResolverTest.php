<?php

use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\HazmatClass;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\SpecialService;
use App\Services\SpecialServiceResolver;

function resolverSpecialService(string $code, bool $active = true): SpecialService
{
    return SpecialService::create([
        'code' => $code,
        'name' => ucwords(str_replace('_', ' ', $code)),
        'scope' => 'package',
        'category' => 'compliance',
        'requires_value' => false,
        'active' => $active,
    ]);
}

it('resolves product compliance codes keyed by triggering product id', function (): void {
    resolverSpecialService('alcohol');
    resolverSpecialService('dry_ice');

    $package = Package::factory()->create();
    $alcoholProduct = Product::factory()->create(['contains_alcohol' => true]);
    $dryIceProduct = Product::factory()->create(['hazmat_class' => HazmatClass::DryIce]);
    PackageItem::factory()->for($package)->create(['product_id' => $alcoholProduct->id]);
    PackageItem::factory()->for($package)->create(['product_id' => $dryIceProduct->id]);

    $codes = app(SpecialServiceResolver::class)->resolveProductRequiredCodes($package);

    expect($codes->all())->toBe([
        'alcohol' => $alcoholProduct->id,
        'dry_ice' => $dryIceProduct->id,
    ]);
});

it('ignores product compliance codes whose special service is inactive', function (): void {
    resolverSpecialService('alcohol', active: false);
    resolverSpecialService('dry_ice');

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'contains_alcohol' => true,
        'hazmat_class' => HazmatClass::DryIce,
    ]);
    PackageItem::factory()->for($package)->create(['product_id' => $product->id]);

    $codes = app(SpecialServiceResolver::class)->resolveProductRequiredCodes($package);

    expect($codes->all())->toBe(['dry_ice' => $product->id]);
});

it('ignores product compliance codes with no matching special service row', function (): void {
    $package = Package::factory()->create();
    $product = Product::factory()->create(['contains_alcohol' => true]);
    PackageItem::factory()->for($package)->create(['product_id' => $product->id]);

    $codes = app(SpecialServiceResolver::class)->resolveProductRequiredCodes($package);

    expect($codes)->toBeEmpty();
});

it('groups shipping method codes by pivot mode and ignores available mode', function (): void {
    $required = resolverSpecialService('signature_required');
    $default = resolverSpecialService('saturday_delivery');
    $available = resolverSpecialService('hold_at_location');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($required->id, ['mode' => 'required']);
    $shippingMethod->specialServices()->attach($default->id, ['mode' => 'default']);
    $shippingMethod->specialServices()->attach($available->id, ['mode' => 'available']);

    $byMode = app(SpecialServiceResolver::class)->methodCodesByMode($shippingMethod);

    expect($byMode['required'])->toBe(['signature_required'])
        ->and($byMode['default'])->toBe(['saturday_delivery']);
});

it('returns empty mode groups without a shipping method', function (): void {
    $byMode = app(SpecialServiceResolver::class)->methodCodesByMode(null);

    expect($byMode)->toBe(['required' => [], 'default' => []]);
});

it('merges shipping method and product codes for a package', function (): void {
    $saturday = resolverSpecialService('saturday_delivery');
    resolverSpecialService('alcohol');

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'default']);

    $shipment = Shipment::factory()->for($shippingMethod)->create();
    $package = Package::factory()->for($shipment)->create();
    $product = Product::factory()->create(['contains_alcohol' => true]);
    PackageItem::factory()->for($package)->create(['product_id' => $product->id]);

    $codes = app(SpecialServiceResolver::class)->resolveForPackage($package);

    expect($codes)->toContain('saturday_delivery')
        ->and($codes)->toContain('alcohol')
        ->and($codes)->toHaveCount(2);
});

it('ignores inactive services attached to a shipping method', function (): void {
    $active = resolverSpecialService('saturday_delivery');
    $stale = resolverSpecialService('signature_required', active: false);

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($stale->id, ['mode' => 'required']);
    $shippingMethod->specialServices()->attach($active->id, ['mode' => 'default']);

    $byMode = app(SpecialServiceResolver::class)->methodCodesByMode($shippingMethod);

    expect($byMode['required'])->toBeEmpty()
        ->and($byMode['default'])->toBe(['saturday_delivery']);
});

it('strips default codes not scoped for the selected rate carrier service', function (): void {
    $saturday = resolverSpecialService('saturday_delivery');

    $fedex = Carrier::factory()->fedex()->create();
    $ground = CarrierService::factory()->fedexGround()->for($fedex)->create();
    $overnight = CarrierService::factory()->for($fedex)->create([
        'name' => 'FedEx Priority Overnight',
        'service_code' => 'PRIORITY_OVERNIGHT',
    ]);
    $saturday->carrierServices()->attach($overnight->id);

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'default']);

    $shipment = Shipment::factory()->for($shippingMethod)->create();
    $package = Package::factory()->for($shipment)->create();

    $resolver = app(SpecialServiceResolver::class);

    // Ground is scoped out for Saturday — the code must not reach the purchase request
    expect($resolver->resolveForPackageAndRate($package, fedexRateFor('FEDEX_GROUND')))->toBeEmpty()
        // Overnight is scoped in — the code survives
        ->and($resolver->resolveForPackageAndRate($package, fedexRateFor('PRIORITY_OVERNIGHT')))->toBe(['saturday_delivery']);
});

it('keeps default codes unscoped for the selected rate carrier', function (): void {
    $saturday = resolverSpecialService('saturday_delivery');

    $usps = Carrier::factory()->usps()->create();
    CarrierService::factory()->uspsGroundAdvantage()->for($usps)->create();

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'default']);

    $shipment = Shipment::factory()->for($shippingMethod)->create();
    $package = Package::factory()->for($shipment)->create();

    $rate = new RateResponse(
        carrier: 'USPS',
        serviceCode: 'USPS_GROUND_ADVANTAGE',
        serviceName: 'USPS Ground Advantage',
        price: 8.50,
    );

    // No Saturday scope rows exist for any USPS service — unrestricted
    expect(app(SpecialServiceResolver::class)->resolveForPackageAndRate($package, $rate))
        ->toBe(['saturday_delivery']);
});

it('applies restricted_countries when resolving for a selected rate', function (): void {
    $saturday = resolverSpecialService('saturday_delivery');

    $fedex = Carrier::factory()->fedex()->create();
    $overnight = CarrierService::factory()->for($fedex)->create([
        'name' => 'FedEx Priority Overnight',
        'service_code' => 'PRIORITY_OVERNIGHT',
    ]);
    $saturday->carrierServices()->attach($overnight->id, ['restricted_countries' => ['CA']]);

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'default']);

    $shipment = Shipment::factory()->for($shippingMethod)->create(['country' => 'US']);
    $package = Package::factory()->for($shipment)->create();

    expect(app(SpecialServiceResolver::class)->resolveForPackageAndRate($package, fedexRateFor('PRIORITY_OVERNIGHT')))
        ->toBeEmpty();
});

it('never strips required codes when resolving for a selected rate', function (): void {
    $saturday = resolverSpecialService('saturday_delivery');

    $fedex = Carrier::factory()->fedex()->create();
    CarrierService::factory()->fedexGround()->for($fedex)->create();
    $overnight = CarrierService::factory()->for($fedex)->create([
        'name' => 'FedEx Priority Overnight',
        'service_code' => 'PRIORITY_OVERNIGHT',
    ]);
    $saturday->carrierServices()->attach($overnight->id);

    $shippingMethod = ShippingMethod::factory()->create();
    $shippingMethod->specialServices()->attach($saturday->id, ['mode' => 'required']);

    $shipment = Shipment::factory()->for($shippingMethod)->create();
    $package = Package::factory()->for($shipment)->create();

    // Required codes pass through untouched — rate shopping already excluded
    // carrier services that can't satisfy them
    expect(app(SpecialServiceResolver::class)->resolveForPackageAndRate($package, fedexRateFor('FEDEX_GROUND')))
        ->toBe(['saturday_delivery']);
});

function fedexRateFor(string $serviceCode): RateResponse
{
    return new RateResponse(
        carrier: 'FedEx',
        serviceCode: $serviceCode,
        serviceName: $serviceCode,
        price: 20.00,
    );
}
