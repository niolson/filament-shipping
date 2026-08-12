<?php

namespace App\Services\Carriers\Concerns;

use App\DataTransferObjects\Shipping\ShipRequest;

/**
 * Trims the ship request's label references down to what a carrier's reference
 * field will accept. Each adapter supplies its own limits and then shapes the
 * values into its payload, since no two carriers agree on the field's name.
 */
trait BuildsCustomerReferences
{
    /**
     * @return array<int, string>
     */
    private function labelReferences(ShipRequest $request, int $maxLength, int $maxCount): array
    {
        $references = [];

        foreach ($request->references as $reference) {
            if (count($references) >= $maxCount) {
                break;
            }

            $reference = mb_substr(trim($reference), 0, $maxLength);

            if ($reference !== '') {
                $references[] = $reference;
            }
        }

        return $references;
    }
}
