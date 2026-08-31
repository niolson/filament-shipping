<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\ClassifiedRate;
use App\DataTransferObjects\Shipping\RateResponse;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RateSelector
{
    /**
     * Classify and sort rates into on-time then late, each group sorted cheapest first.
     * "On-time" requires a known delivery date on or before the deadline.
     * Unknown delivery date with a deadline counts as late.
     * No deadline = all rates classified as on-time.
     *
     * Rates whose price is only known at purchase time (Shopify Shipping) sort
     * after every priced rate in their group, so an unpriced option is never
     * mistaken for the cheapest one — it only wins when nothing else is offered.
     *
     * @param  Collection<int, RateResponse>  $rates
     * @return Collection<int, ClassifiedRate>
     */
    public function classify(Collection $rates, ?Carbon $deadline): Collection
    {
        $classified = $rates->map(
            fn (RateResponse $rate) => new ClassifiedRate(
                rate: $rate,
                isOnTime: $this->isOnTime($rate, $deadline),
            )
        );

        $onTime = $classified
            ->filter(fn (ClassifiedRate $cr) => $cr->isOnTime)
            ->sortBy(fn (ClassifiedRate $cr) => $this->sortKey($cr->rate));

        $late = $classified
            ->filter(fn (ClassifiedRate $cr) => ! $cr->isOnTime)
            ->sortBy(fn (ClassifiedRate $cr) => $this->sortKey($cr->rate));

        return $onTime->merge($late)->values();
    }

    /**
     * Select the best rate: cheapest on-time when a deadline exists, otherwise cheapest overall.
     *
     * @param  Collection<int, RateResponse>  $rates
     */
    public function selectBest(Collection $rates, ?Carbon $deadline): RateResponse
    {
        return $this->classify($rates, $deadline)->first()->rate;
    }

    /**
     * @return array{0: int, 1: float}
     */
    private function sortKey(RateResponse $rate): array
    {
        return [$rate->priceUnknown ? 1 : 0, $rate->price];
    }

    private function isOnTime(RateResponse $rate, ?Carbon $deadline): bool
    {
        if (! $deadline) {
            return true;
        }

        $deliveryDate = $rate->parsedDeliveryDate();

        // Deadlines are calendar dates (midnight); ignore the carrier's time-of-day
        // commitment so a same-day delivery at, e.g., 5pm isn't flagged late.
        return $deliveryDate !== null && $deliveryDate->startOfDay()->lte($deadline->copy()->startOfDay());
    }
}
