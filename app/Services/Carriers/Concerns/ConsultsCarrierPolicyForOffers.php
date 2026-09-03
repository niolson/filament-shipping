<?php

namespace App\Services\Carriers\Concerns;

use App\Contracts\CarrierPolicy;
use App\Enums\ServiceCapability;

/**
 * The offer seam for a direct carrier, which is where it collapses back into
 * carrier policy.
 *
 * ADR-0002 decision 8 moved hard-required special-service evaluation to the
 * offer, because Amazon returns capabilities per rate and Shopify can promise
 * nothing at all. For a carrier we buy from directly the two questions still
 * have one answer — the offer is the carrier's — so this delegates rather than
 * restating anything.
 *
 * @phpstan-require-implements CarrierPolicy
 */
trait ConsultsCarrierPolicyForOffers
{
    public function offerCapability(string $serviceCode): ServiceCapability
    {
        return $this->serviceCapability($serviceCode);
    }

    public function offerDeclaredValueCap(): ?float
    {
        return $this->declaredValueCap();
    }
}
