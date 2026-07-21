<?php

namespace App\Exceptions\Carriers;

/**
 * The carrier cannot be reached because of how the app is currently configured
 * (e.g. sandbox mode combined with an OAuth-connected account), not because a
 * request failed. Callers should degrade gracefully rather than treat it as a bug.
 */
class CarrierUnavailableException extends CarrierException
{
    public function __construct(string $carrier, string $reason)
    {
        parent::__construct($carrier, $reason);
    }
}
