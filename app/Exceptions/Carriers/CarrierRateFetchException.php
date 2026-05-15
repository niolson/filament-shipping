<?php

namespace App\Exceptions\Carriers;

use Throwable;

class CarrierRateFetchException extends CarrierException
{
    public function __construct(string $carrier, ?Throwable $previous = null)
    {
        parent::__construct($carrier, "Failed to fetch rates from {$carrier}", $previous);
    }
}
