<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\ServiceCapability;
use App\Models\Package;
use Illuminate\Support\Collection;

/**
 * What can put an offer in front of a packer and sell it.
 *
 * Reduced by ADR-0002 decision 7 to just that. Voiding, tracking and manifest
 * eligibility moved to {@see PostageSourceOperations}; what a carrier is and
 * will carry moved to {@see CarrierPolicy}. A direct carrier is all three at
 * once — see {@see DirectCarrierAdapter} — but Shopify Shipping is only this
 * one, and used to have to answer the rest with no-ops.
 *
 * `CarrierRegistry` still keys this by carrier name, which is right for quoting
 * and buying and wrong for everything else (decision 6).
 */
interface CarrierAdapterInterface
{
    /**
     * The name this offer is filed under in `CarrierRegistry`.
     */
    public function getCarrierName(): string;

    /**
     * Whether this adapter is configured well enough to be asked for offers.
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
     * Get shipping rates for the given request (synchronous).
     *
     * The only quoting method every offer source has. One with a rate API
     * implements {@see AsyncRateQuoting} as well and is normally quoted through
     * that instead, off the packer's critical path.
     *
     * @param  array<string>  $serviceCodes  Filter to these service codes only
     * @return Collection<int, RateResponse>
     */
    public function getRates(RateRequest $request, array $serviceCodes): Collection;

    /**
     * Buy the label and return the result with tracking/label info.
     */
    public function createShipment(ShipRequest $request): ShipResponse;

    /**
     * Resolve a rule-pre-selected rate into a fully-qualified rate with metadata.
     *
     * For carriers like USPS where one service code maps to many rate variants
     * (cubic tiers, single-piece, etc.), this fetches rates and picks the cheapest
     * matching variant. Other carriers return the rate as-is.
     */
    public function resolvePreSelectedRate(RateResponse $rate, Package $package): RateResponse;
}
