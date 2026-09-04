<?php

use App\DataTransferObjects\PostageSources\ServiceObservation;
use App\Enums\SourceEnvironment;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\ObservedService;
use App\Models\Setting;
use App\Services\PostageSources\ObservedServiceRecorder;
use App\Services\SettingsService;

function observation(
    string $carrierId = 'ONTRAC',
    string $serviceId = 'ONTRAC_MFN_GROUND',
    bool $eligible = true,
    ?string $marketplace = 'ATVPDKIKX0DER',
    ?string $carrierName = 'OnTrac',
    ?string $serviceName = 'OnTrac Ground',
): ServiceObservation {
    return new ServiceObservation(
        source: 'amazon',
        externalCarrierId: $carrierId,
        externalServiceId: $serviceId,
        externalCarrierName: $carrierName,
        externalServiceName: $serviceName,
        marketplace: $marketplace,
        eligible: $eligible,
    );
}

it('records a service identity the first time it is seen', function (): void {
    app(ObservedServiceRecorder::class)->record([observation()]);

    $service = ObservedService::sole();

    expect($service->external_carrier_id)->toBe('ONTRAC')
        ->and($service->external_service_id)->toBe('ONTRAC_MFN_GROUND')
        ->and($service->environment)->toBe(SourceEnvironment::Production)
        ->and($service->observation_count)->toBe(1)
        ->and($service->first_seen_at)->not->toBeNull()
        ->and($service->hasBeenEligible())->toBeTrue()
        ->and($service->isMapped())->toBeFalse();
});

it('deduplicates on source, environment, marketplace, carrier and service', function (): void {
    $recorder = app(ObservedServiceRecorder::class);

    $recorder->record([observation()]);
    $recorder->record([observation()]);
    $recorder->record([observation()]);

    expect(ObservedService::count())->toBe(1)
        ->and(ObservedService::sole()->observation_count)->toBe(3);
});

it('keeps the same identity apart across environments', function (): void {
    app(ObservedServiceRecorder::class)->record([observation()]);

    Setting::updateOrCreate(['key' => 'sandbox_mode'], ['value' => '1', 'type' => 'boolean', 'group' => 'system']);
    app(SettingsService::class)->clearCache();

    app(ObservedServiceRecorder::class)->record([observation()]);

    // Amazon's sandbox returned only Amazon Shipping where production returned
    // OnTrac, UPS and USPS and no Amazon Shipping at all. Merging the two would
    // let a sandbox sighting vouch for a production identity.
    expect(ObservedService::count())->toBe(2)
        ->and(ObservedService::pluck('environment')->map->value->sort()->values()->all())
        ->toBe(['production', 'sandbox']);
});

it('keeps a source with no marketplace deduplicating', function (): void {
    $recorder = app(ObservedServiceRecorder::class);

    $recorder->record([observation(marketplace: null)]);
    $recorder->record([observation(marketplace: null)]);

    expect(ObservedService::count())->toBe(1)
        ->and(ObservedService::sole()->marketplace)->toBe('')
        ->and(ObservedService::sole()->observation_count)->toBe(2);
});

it('collapses repeated identities inside one response', function (): void {
    // Amazon returned three USPS Priority Mail Express variants differing only
    // in flat-rate packaging.
    app(ObservedServiceRecorder::class)->record([
        observation(carrierId: 'USPS', serviceId: 'USPS_PME_FR_ENV'),
        observation(carrierId: 'USPS', serviceId: 'USPS_PME_FR_ENV'),
        observation(carrierId: 'USPS', serviceId: 'USPS_PME_FR_ENV'),
    ]);

    expect(ObservedService::count())->toBe(1)
        ->and(ObservedService::sole()->observation_count)->toBe(1);
});

it('records an identity seen only as ineligible without marking it buyable', function (): void {
    app(ObservedServiceRecorder::class)->record([
        observation(carrierId: 'YANWEN', serviceId: 'YANWEN_STD', eligible: false),
    ]);

    $service = ObservedService::sole();

    expect($service->observation_count)->toBe(1)
        ->and($service->last_seen_at)->not->toBeNull()
        ->and($service->hasBeenEligible())->toBeFalse();
});

it('keeps the date a service was last buyable when it turns ineligible', function (): void {
    $recorder = app(ObservedServiceRecorder::class);

    $recorder->record([observation(eligible: true)]);
    $eligibleAt = ObservedService::sole()->last_eligible_at;

    $this->travel(1)->days();
    $recorder->record([observation(eligible: false)]);

    $service = ObservedService::sole();

    expect($service->last_eligible_at->timestamp)->toBe($eligibleAt->timestamp)
        ->and($service->last_seen_at->isAfter($eligibleAt))->toBeTrue()
        ->and($service->observation_count)->toBe(2);
});

it('never creates a carrier or a carrier service from an observation', function (): void {
    app(ObservedServiceRecorder::class)->record([
        observation(),
        observation(carrierId: 'UPS', serviceId: 'UPS_PTP_NEXT_DAY_AIR', carrierName: 'UPS'),
    ]);

    // ADR-0003 decision 2: promotion into the authored catalog is a deliberate
    // human act, never a side effect of discovery.
    expect(Carrier::count())->toBe(0)
        ->and(CarrierService::count())->toBe(0)
        ->and(ObservedService::count())->toBe(2);
});

it('carries through a renamed service without losing its identity', function (): void {
    $recorder = app(ObservedServiceRecorder::class);

    $recorder->record([observation(serviceName: 'OnTrac Ground')]);
    $recorder->record([observation(serviceName: 'OnTrac Ground Saver')]);

    expect(ObservedService::count())->toBe(1)
        ->and(ObservedService::sole()->external_service_name)->toBe('OnTrac Ground Saver');
});

it('records a full quote of eligible and ineligible services in a handful of queries', function (): void {
    $observations = collect(range(1, 105))->map(fn (int $i): ServiceObservation => observation(
        carrierId: 'CARRIER_'.($i % 14),
        serviceId: 'SERVICE_'.$i,
        eligible: $i <= 6,
    ))->all();

    DB::enableQueryLog();
    app(ObservedServiceRecorder::class)->record($observations);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // This runs on the Ship page for every quote, so it must not grow a query
    // per service: 105 identities is one production response.
    expect(ObservedService::count())->toBe(105)
        ->and($queries)->toBeLessThan(12);
});

it('does not touch or return a service nobody observed', function (): void {
    $recorder = app(ObservedServiceRecorder::class);

    // (UPS, NEXT_DAY) and (ONTRAC, GROUND) already on file.
    $recorder->record([
        observation(carrierId: 'UPS', serviceId: 'NEXT_DAY'),
        observation(carrierId: 'ONTRAC', serviceId: 'GROUND'),
    ]);

    // Now a quote returning (UPS, GROUND) and (ONTRAC, NEXT_DAY): the two
    // cross terms of the same carrier and service sets. A query that matches
    // carriers and services independently also loads the two rows above.
    $returned = $recorder->record([
        observation(carrierId: 'UPS', serviceId: 'GROUND'),
        observation(carrierId: 'ONTRAC', serviceId: 'NEXT_DAY'),
    ]);

    $counts = ObservedService::query()
        ->get()
        ->mapWithKeys(fn (ObservedService $s): array => [
            $s->external_carrier_id.':'.$s->external_service_id => $s->observation_count,
        ]);

    expect($returned)->toHaveCount(2)
        ->and($returned->map(fn (ObservedService $s): string => $s->external_carrier_id.':'.$s->external_service_id)->sort()->values()->all())
        ->toBe(['ONTRAC:NEXT_DAY', 'UPS:GROUND'])
        ->and($counts->all())->toBe([
            'UPS:NEXT_DAY' => 1,
            'ONTRAC:GROUND' => 1,
            'UPS:GROUND' => 1,
            'ONTRAC:NEXT_DAY' => 1,
        ]);
});
