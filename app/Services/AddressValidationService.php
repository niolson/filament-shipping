<?php

namespace App\Services;

use App\Contracts\AddressValidationInterface;
use App\Enums\Deliverability;
use App\Events\AddressValidationFailed;
use App\Models\Shipment;

class AddressValidationService
{
    /**
     * @param  array<AddressValidationInterface>  $validators
     */
    public function __construct(
        private readonly array $validators = [],
    ) {}

    /**
     * Validate the shipment's address by dispatching to the appropriate
     * country-specific validator. Skips gracefully if no validator supports
     * the shipment's country.
     */
    public function validate(Shipment $shipment): void
    {
        $country = $shipment->country ?? 'US';

        foreach ($this->validators as $validator) {
            if (! $validator->supports($country)) {
                continue;
            }

            $validator->validate($shipment);

            if ($shipment->checked) {
                break;
            }
        }

        // Dispatched once the whole fallback chain has had its turn, so an
        // early validator's inconclusive "no" (still open to fallback) can't
        // produce a failure log a later validator then contradicts.
        if ($shipment->deliverability === Deliverability::No) {
            AddressValidationFailed::dispatch($shipment, $shipment->validation_message ?? 'Address validation failed');
        }
    }
}
