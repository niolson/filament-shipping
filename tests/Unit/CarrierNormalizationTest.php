<?php

use App\Models\Carrier;
use App\Models\CarrierAlias;
use App\Services\CarrierNormalizer;
use App\Services\Carriers\CarrierRegistry;
use Database\Seeders\CarrierAliasSeeder;

it('normalizes canonical names and aliases without using the carrier registry', function (): void {
    $usps = Carrier::factory()->usps()->create();
    CarrierAlias::factory()->for($usps)->create(['alias' => 'US Postal Service']);

    $registry = Mockery::mock(CarrierRegistry::class);
    $registry->shouldNotReceive('get', 'has', 'getCarrierNames', 'getConfiguredAdapters');
    app()->instance(CarrierRegistry::class, $registry);

    $normalizer = app(CarrierNormalizer::class);

    expect($normalizer->resolve('  usps  ')?->is($usps))->toBeTrue()
        ->and($normalizer->resolve('US   Postal Service')?->is($usps))->toBeTrue()
        ->and($normalizer->resolve('Unknown Carrier'))->toBeNull()
        ->and($normalizer->resolve(null))->toBeNull();
});

it('updates the lookup key when an alias is edited', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'Canonical Carrier']);
    $alias = CarrierAlias::factory()->for($carrier)->create(['alias' => 'Old Name']);
    $normalizer = app(CarrierNormalizer::class);

    expect($normalizer->resolve('Old Name')?->is($carrier))->toBeTrue();

    $alias->update(['alias' => 'New Name']);

    expect($normalizer->resolve('Old Name'))->toBeNull()
        ->and($normalizer->resolve('New Name')?->is($carrier))->toBeTrue();
});

it('seeds default aliases even when model events are disabled', function (): void {
    $usps = Carrier::factory()->usps()->create();

    CarrierAlias::withoutEvents(fn () => app(CarrierAliasSeeder::class)->run());

    expect(app(CarrierNormalizer::class)->resolve('United States Postal Service')?->is($usps))->toBeTrue();
});
