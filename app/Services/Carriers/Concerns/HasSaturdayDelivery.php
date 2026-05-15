<?php

namespace App\Services\Carriers\Concerns;

use App\DataTransferObjects\Shipping\RateRequest;

/**
 * Saturday delivery eligibility logic shared by carrier adapters that support it.
 *
 * Each adapter using this trait must define a SATURDAY_DELIVERY_DAY_MAP constant
 * that maps its carrier-specific service codes to the day of week (Carbon dayOfWeek
 * integer) from which Saturday delivery is achievable.
 */
trait HasSaturdayDelivery
{
    /**
     * Classify whether the given service codes are all, none, or mixed eligible
     * for Saturday delivery given the ship date.
     *
     * 'all'   — every service in the list can achieve Saturday delivery
     * 'none'  — no service can achieve Saturday delivery
     * 'mixed' — some can, some cannot (or no service filter was applied)
     */
    private function classifySaturdayEligibility(array $serviceCodes, ?RateRequest $request = null): string
    {
        $today = ($request?->shipDate ?? now())->dayOfWeek;

        // No service filter = carrier returns all service types = always mixed
        if (empty($serviceCodes)) {
            return 'mixed';
        }

        $eligible = 0;
        $ineligible = 0;

        foreach ($serviceCodes as $code) {
            $saturdayDay = static::SATURDAY_DELIVERY_DAY_MAP[$code] ?? null;
            if ($saturdayDay === $today) {
                $eligible++;
            } else {
                $ineligible++;
            }
        }

        if ($ineligible === 0) {
            return 'all';
        }

        if ($eligible === 0) {
            return 'none';
        }

        return 'mixed';
    }

    /**
     * Adjust the rate request for Saturday delivery based on service eligibility.
     * For 'all' eligible: keep Saturday. For 'none' or 'mixed': strip it
     * (mixed sends a follow-up Saturday request in parseRateResponse).
     */
    private function adjustRequestForSaturday(RateRequest $request, array $serviceCodes): RateRequest
    {
        if ($request->saturdayDelivery && $this->classifySaturdayEligibility($serviceCodes, $request) !== 'all') {
            return $this->withoutSaturdayDelivery($request);
        }

        return $request;
    }

    private function withoutSaturdayDelivery(RateRequest $request): RateRequest
    {
        return new RateRequest(
            originPostalCode: $request->originPostalCode,
            destinationPostalCode: $request->destinationPostalCode,
            originCountry: $request->originCountry,
            destinationCountry: $request->destinationCountry,
            destinationCity: $request->destinationCity,
            destinationStateOrProvince: $request->destinationStateOrProvince,
            residential: $request->residential,
            packages: $request->packages,
            saturdayDelivery: false,
            locationId: $request->locationId,
            shipDate: $request->shipDate,
        );
    }
}
