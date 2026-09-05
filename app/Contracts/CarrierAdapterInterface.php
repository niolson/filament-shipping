<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Models\Package;
use Illuminate\Support\Collection;

/**
 * A postage source that can say what a label will cost before buying it.
 *
 * Reduced twice. ADR-0002 decision 7 moved voiding, tracking and manifest
 * eligibility to {@see PostageSourceOperations} and what a carrier is and will
 * carry to {@see CarrierPolicy}; ADR-0003 decision 6 moved everything a source
 * has to answer whether or not it quotes down into {@see PostageOfferSource},
 * leaving this as the quoting half alone.
 *
 * What remains is the ability to return a {@see RateResponse} — a carrier, a
 * service and a price that were actually offered. A source that cannot state
 * those implements {@see BlindPurchaseSource} instead and never fabricates
 * them.
 */
interface CarrierAdapterInterface extends PostageOfferSource
{
    /**
     * Get shipping rates for the given request (synchronous).
     *
     * The only quoting method every rate source has. One with a rate API
     * implements {@see AsyncRateQuoting} as well and is normally quoted through
     * that instead, off the packer's critical path.
     *
     * @param  array<string>  $serviceCodes  Filter to these service codes only
     * @return Collection<int, RateResponse>
     */
    public function getRates(RateRequest $request, array $serviceCodes): Collection;

    /**
     * Resolve a rule-pre-selected rate into a fully-qualified rate with metadata.
     *
     * For carriers like USPS where one service code maps to many rate variants
     * (cubic tiers, single-piece, etc.), this fetches rates and picks the cheapest
     * matching variant. Other carriers return the rate as-is.
     */
    public function resolvePreSelectedRate(RateResponse $rate, Package $package): RateResponse;
}
