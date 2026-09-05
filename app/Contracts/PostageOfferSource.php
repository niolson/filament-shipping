<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\ServiceCapability;

/**
 * Something that can put postage in front of a packer and sell it.
 *
 * What it cannot say is how much it costs. That is the split ADR-0003 decision
 * 6 forces: a source with a rate API quotes, and implements
 * {@see CarrierAdapterInterface}; a source with none advertises a blind
 * purchase, and implements {@see BlindPurchaseSource}. Everything below is what
 * both have to answer regardless — who they are, whether they are usable, what
 * they can promise, and how to buy.
 *
 * `CarrierRegistry` keys these by carrier name, which is right for quoting and
 * buying and wrong for everything else (ADR-0002 decision 6).
 */
interface PostageOfferSource
{
    /**
     * The name this offer is filed under in `CarrierRegistry`.
     */
    public function getCarrierName(): string;

    /**
     * Whether this source is configured well enough to be asked for offers.
     */
    public function isConfigured(): bool;

    /**
     * Whether an offer from here can honour a special service code.
     *
     * The offer seam, not carrier policy (ADR-0002 decision 8). A direct carrier
     * consults {@see CarrierPolicy::serviceCapability()} and nothing else
     * changes. A resale channel answers for itself: Shopify picks the carrier
     * and the rate after purchase, so it can guarantee nothing and reports
     * {@see ServiceCapability::Unguaranteed} — excluded, visibly, whenever the
     * shipment hard-requires the service, and skipped when it is only a default.
     */
    public function offerCapability(string $serviceCode): ServiceCapability;

    /**
     * Maximum declared value an offer from here can carry, or null when
     * unlimited/not applicable. Rate shopping excludes the offer (visibly) when
     * the package's declared value exceeds this — never clamps silently.
     */
    public function offerDeclaredValueCap(): ?float;

    /**
     * Buy the label and return the result with tracking/label info.
     */
    public function createShipment(ShipRequest $request): ShipResponse;
}
