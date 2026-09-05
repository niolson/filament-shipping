<?php

namespace App\Services\PostageSources;

use App\DataTransferObjects\PostageSources\ServiceObservation;
use App\Enums\SourceEnvironment;
use App\Models\ObservedService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Writes what a postage source reported into the durable identity store.
 *
 * Deduplication is the whole job. A single Amazon `getRates` returns a handful
 * of eligible offers and upwards of a hundred ineligible ones, and every quote
 * for every parcel returns broadly the same catalog again — so this runs on the
 * hot path of the Ship page and must not grow a query per service.
 *
 * Nothing here creates a `Carrier` or a `CarrierService`. ADR-0003 decision 2
 * is explicit that promotion into the authored catalog is a human act; this
 * class only ever records that an identity was seen.
 *
 * The one thing it does carry across is a mapping a human already made — a new
 * row for a service someone has named inherits that name, because a mapping is
 * about the service and not about the sighting. That makes this the second
 * writer of `carrier_service_id`, so the read and the insert are taken under
 * {@see ObservedService::MAPPING_LOCK} together. See {@see existingMappings()}.
 */
class ObservedServiceRecorder
{
    /** How long a held mapping lock stays valid if the holder dies mid-write. */
    private const LOCK_SECONDS = 10;

    /** How long to wait for the mapping page to finish a write before giving up. */
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * @param  iterable<ServiceObservation>  $observations
     * @return Collection<int, ObservedService>
     */
    public function record(iterable $observations): Collection
    {
        // The same identity can appear twice in one response — Amazon returned
        // three USPS Priority Mail Express variants differing only in flat-rate
        // packaging. Collapse them, letting an eligible mention outrank an
        // ineligible one for the same identity.
        $observations = collect($observations)->reduce(
            function (Collection $unique, ServiceObservation $observation): Collection {
                $key = $this->identityKey($observation);

                if (! $unique->get($key)?->eligible) {
                    $unique->put($key, $observation);
                }

                return $unique;
            },
            collect(),
        )->values();

        if ($observations->isEmpty()) {
            return collect();
        }

        $environment = SourceEnvironment::current();
        $now = now();

        $existing = $this->existingIdentities($observations, $environment);

        $this->insertMissing($observations, $existing, $environment, $now);

        // Re-read so newly inserted rows join the ones already on file; both
        // then take the same increment, which is what keeps observation_count
        // meaning "times seen" rather than "times seen since it existed".
        $rows = $this->existingIdentities($observations, $environment);

        $this->touch($observations, $rows, $now);
        $this->refreshChangedNames($observations, $rows);

        return $this->existingIdentities($observations, $environment)->values();
    }

    /**
     * The rows already on file for exactly these identities.
     *
     * The `whereIn` clauses are independent, so the database is being asked a
     * broader question than the one being answered: carriers {A, B} crossed
     * with services {X, Y} matches an existing (A, Y) that nobody observed.
     * That is deliberate — one indexed query beats a hundred tuple predicates —
     * but the cross terms have to be dropped before the result is used, or an
     * unrelated service is touched, counted and returned to the caller.
     *
     * @param  Collection<int, ServiceObservation>  $observations
     * @return Collection<string, ObservedService> keyed by identity
     */
    private function existingIdentities(Collection $observations, SourceEnvironment $environment): Collection
    {
        $requested = $observations
            ->map(fn (ServiceObservation $observation): string => $this->identityKey($observation))
            ->flip();

        return ObservedService::query()
            ->whereIn('source', $observations->pluck('source')->unique()->all())
            ->where('environment', $environment)
            ->whereIn('marketplace', $observations->map(fn (ServiceObservation $o): string => $o->marketplace ?? '')->unique()->all())
            ->whereIn('external_carrier_id', $observations->pluck('externalCarrierId')->unique()->all())
            ->whereIn('external_service_id', $observations->pluck('externalServiceId')->unique()->all())
            ->get()
            ->keyBy(fn (ObservedService $service): string => implode('|', [
                $service->source,
                $service->marketplace,
                $service->external_carrier_id,
                $service->external_service_id,
            ]))
            ->filter(fn (ObservedService $service, string $key): bool => $requested->has($key));
    }

    /**
     * @param  Collection<int, ServiceObservation>  $observations
     * @param  Collection<string, ObservedService>  $existing
     */
    private function insertMissing(
        Collection $observations,
        Collection $existing,
        SourceEnvironment $environment,
        CarbonInterface $now,
    ): void {
        $new = $observations
            ->reject(fn (ServiceObservation $observation): bool => $existing->has($this->identityKey($observation)))
            ->values();

        if ($new->isEmpty()) {
            return;
        }

        // Read and insert under one lock. Apart, an operator mapping a service
        // in the window between them lands on rows that already exist and never
        // on the one being inserted, and an operator unmapping in the same
        // window is overwritten by a value read a moment before it was
        // withdrawn. Either way the new row disagrees with the last decision a
        // human made, silently and for good. See {@see ObservedService::MAPPING_LOCK}.
        Cache::lock(ObservedService::MAPPING_LOCK, self::LOCK_SECONDS)->block(
            self::LOCK_WAIT_SECONDS,
            function () use ($new, $environment, $now): void {
                $this->insertNew($new, $this->existingMappings($new), $environment, $now);
            },
        );
    }

    /**
     * @param  Collection<int, ServiceObservation>  $new
     * @param  Collection<string, int>  $mappings
     */
    private function insertNew(
        Collection $new,
        Collection $mappings,
        SourceEnvironment $environment,
        CarbonInterface $now,
    ): void {
        // insertOrIgnore rather than insert: two packers quoting the same
        // parcel at once both find the identity missing, and the unique
        // index is what settles it. Losing that race is not an error.
        ObservedService::query()->insertOrIgnore(
            $new->map(fn (ServiceObservation $observation): array => [
                'source' => $observation->source,
                'environment' => $environment->value,
                'marketplace' => $observation->marketplace ?? '',
                'external_carrier_id' => $observation->externalCarrierId,
                'external_carrier_name' => $observation->externalCarrierName,
                'external_service_id' => $observation->externalServiceId,
                'external_service_name' => $observation->externalServiceName,
                'carrier_service_id' => $mappings->get($this->serviceKey($observation)),
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                // Zero, not one: the increment below is what counts this
                // sighting, and it applies to new and existing rows alike.
                'observation_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
        );
    }

    /**
     * Mappings a human has already made for these services, in whatever
     * environment or marketplace they made them.
     *
     * Without this, a mapping would only ever cover the rows that existed when
     * it was made: map Amazon's `USPS/USPS_GROUND_ADVANTAGE` in production
     * today, flip to sandbox tomorrow, and the same service arrives as a new
     * identity with nothing on it — permanently unmapped through no decision of
     * anyone's. {@see ObservedService::scopeSameService()} defines the scope
     * both halves read.
     *
     * This is not discovery creating catalog rows. It copies a `carrier_service_id`
     * a person already chose onto another sighting of the service they chose it
     * for; it cannot produce an identifier nobody authored.
     *
     * One extra query, and only when a quote brought back a service we have
     * never recorded — the ordinary case inserts nothing and never gets here.
     *
     * @param  Collection<int, ServiceObservation>  $observations
     * @return Collection<string, int> carrier_service_id keyed by service
     */
    private function existingMappings(Collection $observations): Collection
    {
        return ObservedService::query()
            ->whereIn('source', $observations->pluck('source')->unique()->all())
            ->whereIn('external_carrier_id', $observations->pluck('externalCarrierId')->unique()->all())
            ->whereIn('external_service_id', $observations->pluck('externalServiceId')->unique()->all())
            ->whereNotNull('carrier_service_id')
            // Same independent-whereIn caveat as existingIdentities(): rows for
            // cross terms nobody observed come back too. Harmless here — they
            // key under their own service and are never looked up — and the
            // mapper keeps every row for one service on one carrier service, so
            // duplicate keys cannot disagree.
            ->get(['source', 'external_carrier_id', 'external_service_id', 'carrier_service_id'])
            ->keyBy(fn (ObservedService $service): string => ObservedService::serviceKey(
                $service->source,
                $service->external_carrier_id,
                $service->external_service_id,
            ))
            ->map(fn (ObservedService $service): int => $service->carrier_service_id);
    }

    /**
     * @param  Collection<int, ServiceObservation>  $observations
     * @param  Collection<string, ObservedService>  $rows
     */
    private function touch(Collection $observations, Collection $rows, CarbonInterface $now): void
    {
        [$eligible, $ineligible] = $observations->partition(
            fn (ServiceObservation $observation): bool => $observation->eligible
        );

        $idsFor = fn (Collection $group): array => $group
            ->map(fn (ServiceObservation $o): ?int => $rows->get($this->identityKey($o))?->id)
            ->filter()
            ->values()
            ->all();

        if (($ids = $idsFor($eligible)) !== []) {
            ObservedService::query()->whereIn('id', $ids)->increment('observation_count', 1, [
                'last_seen_at' => $now,
                'last_eligible_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (($ids = $idsFor($ineligible)) !== []) {
            // last_eligible_at is deliberately untouched: a service that was
            // buyable last week and is not today keeps the date that says so.
            ObservedService::query()->whereIn('id', $ids)->increment('observation_count', 1, [
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Carry through a renamed service or carrier.
     *
     * Normally updates nothing — names are stable — so this stays a per-row
     * write for the rare case rather than a column in the bulk update.
     *
     * @param  Collection<int, ServiceObservation>  $observations
     * @param  Collection<string, ObservedService>  $rows
     */
    private function refreshChangedNames(Collection $observations, Collection $rows): void
    {
        foreach ($observations as $observation) {
            $row = $rows->get($this->identityKey($observation));

            if (! $row) {
                continue;
            }

            $changed = array_filter([
                'external_carrier_name' => $observation->externalCarrierName,
                'external_service_name' => $observation->externalServiceName,
            ], fn (?string $name, string $column): bool => $name !== null && $name !== $row->{$column},
                ARRAY_FILTER_USE_BOTH);

            if ($changed !== []) {
                ObservedService::query()->whereKey($row->id)->update($changed);
            }
        }
    }

    private function identityKey(ServiceObservation $observation): string
    {
        return implode('|', [
            $observation->source,
            $observation->marketplace ?? '',
            $observation->externalCarrierId,
            $observation->externalServiceId,
        ]);
    }

    /**
     * The identity key without the marketplace — the scope a mapping covers,
     * as opposed to the scope a row is deduplicated on.
     */
    private function serviceKey(ServiceObservation $observation): string
    {
        return ObservedService::serviceKey(
            $observation->source,
            $observation->externalCarrierId,
            $observation->externalServiceId,
        );
    }
}
