<?php

namespace App\Services;

use App\Models\Carrier;
use App\Models\CarrierAlias;
use App\Models\Location;
use App\Services\Carriers\ShopifyAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ShipDateService
{
    private const DEFAULT_PICKUP_DAYS = [1, 2, 3, 4, 5]; // Mon-Fri

    /**
     * Interim cutoff for postage bought where the carrier is not known until
     * after purchase. Deliberately a fixed hour and deliberately not derived
     * from the carriers' own cutoffs: ADR-0002 decision 3 calls for a
     * conservative source-level policy, and choosing between the earliest cutoff
     * among candidate carriers, a fixed hour, and an operator-controlled setting
     * needs a judgment about which carriers Shopify can actually pick. Fixed
     * here so that adding a carrier cutoff cannot quietly move Shopify's ship
     * dates before that judgment is made.
     */
    private const BLIND_POSTAGE_INTERIM_CUTOFF_HOUR = 20;

    public function getShipDate(string $carrierName, ?int $locationId = null): CarbonImmutable
    {
        $carrier = $this->normalize($carrierName);
        $location = $this->resolveLocation($locationId);
        $tz = $location?->timezone ?? 'America/New_York';
        $pivot = $this->getPivot($carrier, $locationId);
        $pickupDays = $this->pickupDaysFor($pivot);
        $lastEndOfDay = $pivot?->last_end_of_day_at ? CarbonImmutable::parse($pivot->last_end_of_day_at) : null;
        $now = CarbonImmutable::now($tz);
        $today = $now->startOfDay();

        // If we already ended the shipping day today (in local time), ship date = next pickup day
        if ($lastEndOfDay && $lastEndOfDay->tz($tz)->isToday()) {
            return $this->getNextPickupDay($pickupDays, $today);
        }

        $cutoffHour = $this->cutoffHourFor($carrierName, $carrier);

        if ($cutoffHour !== null && $now->hour >= $cutoffHour) {
            return $this->getNextPickupDay($pickupDays, $today);
        }

        // Otherwise, today if it's a pickup day, else next pickup day
        if (in_array($today->dayOfWeek, $pickupDays)) {
            return $today;
        }

        return $this->getNextPickupDay($pickupDays, $today);
    }

    public function getNextPickupDay(array|string $pickupDaysOrCarrier, CarbonImmutable|int|null $afterOrLocationId = null, ?CarbonImmutable $after = null): CarbonImmutable
    {
        // Support both calling conventions:
        // getNextPickupDay(array $pickupDays, CarbonImmutable $after)
        // getNextPickupDay(string $carrierName, ?int $locationId, ?CarbonImmutable $after)
        if (is_string($pickupDaysOrCarrier)) {
            $carrierName = $pickupDaysOrCarrier;
            $locationId = $afterOrLocationId;
            $location = $this->resolveLocation($locationId);
            $tz = $location?->timezone ?? 'America/New_York';
            $afterDate = $after ?? CarbonImmutable::today($tz);
            $pickupDays = $this->pickupDaysFor($this->getPivot($this->normalize($carrierName), $locationId));
        } else {
            $pickupDays = $pickupDaysOrCarrier;
            $afterDate = $afterOrLocationId instanceof CarbonImmutable ? $afterOrLocationId : CarbonImmutable::today();
        }

        $date = $afterDate->addDay();

        for ($i = 0; $i < 7; $i++) {
            if (in_array($date->dayOfWeek, $pickupDays)) {
                return $date;
            }
            $date = $date->addDay();
        }

        // Safety: if no pickup days configured, return tomorrow
        return $afterDate->addDay();
    }

    public function endShippingDay(string $carrierName, ?int $locationId = null): void
    {
        $locationId = $locationId ?? Location::getDefault()?->id;

        if (! $locationId) {
            return;
        }

        $carrier = $this->normalize($carrierName);

        if (! $carrier) {
            return;
        }

        $exists = DB::table('carrier_location')
            ->where('carrier_id', $carrier->id)
            ->where('location_id', $locationId)
            ->exists();

        if ($exists) {
            DB::table('carrier_location')
                ->where('carrier_id', $carrier->id)
                ->where('location_id', $locationId)
                ->update([
                    'last_end_of_day_at' => now(),
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('carrier_location')->insert([
                'carrier_id' => $carrier->id,
                'location_id' => $locationId,
                'last_end_of_day_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return array<int, int>
     */
    public function getPickupDays(string $carrierName, ?int $locationId = null): array
    {
        return $this->pickupDaysFor($this->getPivot($this->normalize($carrierName), $locationId));
    }

    /**
     * Resolve the carrier identity a policy lookup should be keyed on. Runs before
     * every pivot and cutoff lookup so that a source's spelling — `US Postal
     * Service`, whatever Amazon returns in `carrierName` — cannot decide whether a
     * carrier rule applies. Resolving to nothing is a valid terminal state: an
     * unmapped carrier simply has no policy of ours.
     */
    private function normalize(string $carrierName): ?Carrier
    {
        return app(CarrierNormalizer::class)->resolve($carrierName);
    }

    /**
     * The local hour after which a parcel misses the day's pickup, or null when
     * the carrier imposes none.
     *
     * The cutoff is a property of the carrier row itself, so it survives an
     * operator renaming that carrier: the normalized identity is what carries
     * the policy, not the display name it happens to have today.
     */
    private function cutoffHourFor(string $rawCarrierName, ?Carrier $carrier): ?int
    {
        // Shopify Shipping cannot take a carrier-derived cutoff at all:
        // `shippingDatetime` goes out in the purchase mutation, before Shopify
        // reveals which carrier it picked. Give it an explicit interim hour
        // rather than letting a non-carrier fall through to no cutoff.
        if ($this->isBlindPostageSource($rawCarrierName, $carrier)) {
            return self::BLIND_POSTAGE_INTERIM_CUTOFF_HOUR;
        }

        return $carrier?->pickup_cutoff_hour;
    }

    /**
     * Whether the name identifies postage bought where the carrier is not known
     * until after purchase, rather than a physical carrier.
     */
    private function isBlindPostageSource(string $rawCarrierName, ?Carrier $carrier): bool
    {
        $shopifyKey = CarrierAlias::lookupKey(ShopifyAdapter::CARRIER_NAME);

        return CarrierAlias::lookupKey($rawCarrierName) === $shopifyKey
            || ($carrier !== null && CarrierAlias::lookupKey($carrier->name) === $shopifyKey);
    }

    /**
     * @return array<int, int>
     */
    private function pickupDaysFor(?object $pivot): array
    {
        if (! $pivot || ! $pivot->pickup_days) {
            return self::DEFAULT_PICKUP_DAYS;
        }

        return json_decode($pivot->pickup_days, true) ?? self::DEFAULT_PICKUP_DAYS;
    }

    private function resolveLocation(?int $locationId = null): ?Location
    {
        if ($locationId) {
            return Location::find($locationId);
        }

        return Location::getDefault();
    }

    private function getPivot(?Carrier $carrier, ?int $locationId = null): ?object
    {
        $locationId = $locationId ?? $this->resolveLocation()?->id;

        if (! $carrier || ! $locationId) {
            return null;
        }

        return DB::table('carrier_location')
            ->where('carrier_id', $carrier->id)
            ->where('location_id', $locationId)
            ->first();
    }
}
