<?php

use App\Models\Carrier;
use App\Models\CarrierAlias;
use App\Models\Location;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\ShipDateService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\CarrierSeeder;

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('advances USPS shipments to the next pickup day after the cutoff hour', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->usps()->create();
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([1, 2, 3, 4, 5])]);

    $shipDate = app(ShipDateService::class)->getShipDate('USPS');

    expect($shipDate->toDateString())->toBe('2026-04-02');
});

it('keeps non-USPS carriers on the current pickup day after the cutoff hour', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->create(['name' => 'FedEx']);
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([1, 2, 3, 4, 5])]);

    $shipDate = app(ShipDateService::class)->getShipDate('FedEx');

    expect($shipDate->toDateString())->toBe('2026-04-01');
});

it('advances to the next pickup day after end of day has already been run', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 10:00:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 10:00:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->usps()->create();
    $carrier->locations()->attach($location->id, [
        'pickup_days' => json_encode([1, 2, 3, 4, 5]),
        'last_end_of_day_at' => Carbon::now('America/New_York'),
    ]);

    $shipDate = app(ShipDateService::class)->getShipDate('USPS');

    expect($shipDate->toDateString())->toBe('2026-04-02');
});

it('creates a carrier-location end-of-day record when one does not exist', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 16:15:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 16:15:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->create(['name' => 'UPS']);

    app(ShipDateService::class)->endShippingDay('UPS', $location->id);

    $pivotRecord = $carrier->locations()
        ->where('locations.id', $location->id)
        ->firstOrFail()
        ->pivot;

    expect($pivotRecord->last_end_of_day_at)->not->toBeNull()
        ->and(Carbon::parse($pivotRecord->last_end_of_day_at)->setTimezone('America/New_York')->toDateTimeString())->toBe('2026-04-01 16:15:00');
});

it('applies the USPS cutoff to a carrier name that only normalizes to USPS', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->usps()->create();
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([1, 2, 3, 4, 5])]);
    CarrierAlias::create(['carrier_id' => $carrier->id, 'alias' => 'US Postal Service']);

    $shipDate = app(ShipDateService::class)->getShipDate('US Postal Service');

    expect($shipDate->toDateString())->toBe('2026-04-02');
});

it('reads pickup days through the normalized carrier identity', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 10:00:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 10:00:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->create(['name' => 'UPS']);
    // Wednesday (3) is deliberately not a pickup day for this carrier.
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([1, 2, 4, 5])]);
    CarrierAlias::create(['carrier_id' => $carrier->id, 'alias' => 'United Parcel Service']);

    $shipDate = app(ShipDateService::class)->getShipDate('United Parcel Service');

    expect($shipDate->toDateString())->toBe('2026-04-02')
        ->and(app(ShipDateService::class)->getPickupDays('United Parcel Service'))->toBe([1, 2, 4, 5]);
});

it('ends the shipping day for a carrier named by an alias', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 16:15:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 16:15:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->create(['name' => 'FedEx']);
    CarrierAlias::create(['carrier_id' => $carrier->id, 'alias' => 'Federal Express']);

    app(ShipDateService::class)->endShippingDay('Federal Express', $location->id);

    expect(app(ShipDateService::class)->getShipDate('FedEx', $location->id)->toDateString())->toBe('2026-04-02');
});

it('keeps the cutoff with the carrier identity when the carrier is renamed', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->usps()->create();
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([1, 2, 3, 4, 5])]);

    // An operator retitles the carrier in the admin. The row — and so the
    // normalized identity a shipped package points at — is unchanged.
    $carrier->update(['name' => 'United States Postal Service']);

    $shipDate = app(ShipDateService::class)->getShipDate('United States Postal Service');

    expect($shipDate->toDateString())->toBe('2026-04-02');
});

it('applies the Shopify cutoff, which cannot be derived from a carrier known only after purchase', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    $location = Location::getDefault();
    $carrier = Carrier::factory()->shopify()->create();
    $carrier->locations()->attach($location->id, ['pickup_days' => json_encode([1, 2, 3, 4, 5])]);

    $shipDate = app(ShipDateService::class)->getShipDate(ShopifyAdapter::CARRIER_NAME);

    expect($shipDate->toDateString())->toBe('2026-04-02');
});

it('takes the Shopify cutoff from the seeded carrier row, not from a branch in the service', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    Location::getDefault();
    (new CarrierSeeder)->run();

    expect(Carrier::query()->where('name', ShopifyAdapter::CARRIER_NAME)->value('pickup_cutoff_hour'))->toBe(20)
        ->and(app(ShipDateService::class)->getShipDate(ShopifyAdapter::CARRIER_NAME)->toDateString())->toBe('2026-04-02');
});

it('gives Shopify no cutoff when no Shopify carrier row exists, which is also when no label can be bought', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    Location::getDefault();

    // ShopifyAdapter::getRates() only advertises services hanging off this row, so
    // a missing row means no Shopify rate is offered and no Shopify label is bought.
    // The date it would have produced is therefore unreachable rather than wrong.
    expect(Carrier::query()->where('name', ShopifyAdapter::CARRIER_NAME)->exists())->toBeFalse();

    $shipDate = app(ShipDateService::class)->getShipDate(ShopifyAdapter::CARRIER_NAME);

    expect($shipDate->toDateString())->toBe('2026-04-01');
});

it('leaves a carrier that normalizes to nothing on the current pickup day', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01 20:30:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 20:30:00', 'America/New_York'));

    Location::getDefault();

    $shipDate = app(ShipDateService::class)->getShipDate('Poste Italiane');

    expect($shipDate->toDateString())->toBe('2026-04-01');
});
