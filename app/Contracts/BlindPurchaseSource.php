<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use Illuminate\Support\Collection;

/**
 * A postage source that sells labels it cannot quote.
 *
 * Shopify Shipping is the only one today, and the reason this contract exists
 * rather than a flag on {@see CarrierAdapterInterface}: its Admin API has no
 * rate operation at all, and its `ShippingLabel` reports no service, no rate
 * and no price before or after purchase. Omitting `preferredRateSelection`
 * leaves Shopify an unconstrained choice — the buyer's delivery method, then
 * shop preference, then Shopify's own recommendation — so what is on offer is
 * a purchase, not a rate (ADR-0003 decision 6).
 *
 * A source declaring this is structurally excluded from every automated path:
 * auto-ship, batch ship, shipping rules and `RateSelector::selectBest()` all
 * work in {@see RateResponse}, and nothing here produces one. Attended selection is not sufficient either — the offers
 * are only advertised for a client that has opted into blind purchase
 * (ADR-0003 decision 5).
 */
interface BlindPurchaseSource extends PostageOfferSource
{
    /**
     * What this source will sell for the request's package, priceless.
     *
     * Empty is the normal answer: a package this source cannot buy for, a
     * client that has not opted in, or a selection this source does not
     * advertise. It is never an error, and never a reason to warn a packer.
     *
     * @param  array<string>  $serviceCodes  Preferences the caller is willing to ask for
     * @return Collection<int, BlindPurchaseOffer>
     */
    public function blindPurchaseOffers(RateRequest $request, array $serviceCodes): Collection;
}
