<?php

namespace App\Services;

use App\Models\Carrier;
use App\Models\CarrierAlias;

class CarrierNormalizer
{
    /**
     * Resolve a source-provided carrier name without consulting adapter dispatch.
     */
    public function resolve(?string $rawCarrier): ?Carrier
    {
        $lookupKey = CarrierAlias::lookupKey($rawCarrier);

        if ($lookupKey === '') {
            return null;
        }

        $carrier = Carrier::query()
            ->get()
            ->first(fn (Carrier $carrier): bool => CarrierAlias::lookupKey($carrier->name) === $lookupKey);

        if ($carrier) {
            return $carrier;
        }

        return CarrierAlias::query()
            ->with('carrier')
            ->where('lookup_key', $lookupKey)
            ->first()
            ?->carrier;
    }
}
