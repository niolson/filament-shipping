<?php

namespace App\Exceptions\Carriers;

use Throwable;

class CarrierLabelCreationException extends CarrierException
{
    public function __construct(string $carrier, ?Throwable $previous = null)
    {
        parent::__construct($carrier, "Failed to create shipment with {$carrier}", $previous);
    }
}
