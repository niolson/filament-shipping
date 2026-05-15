<?php

namespace App\Exceptions\Carriers;

use RuntimeException;
use Throwable;

class CarrierException extends RuntimeException
{
    public function __construct(
        public readonly string $carrier,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?: "{$carrier} error", previous: $previous);
    }
}
