<?php

namespace App\Exceptions\Carriers;

use Throwable;

/**
 * An Amazon Buy Shipping purchase the source answered and refused.
 *
 * Only ever thrown for a *reply*. A timeout or a dropped connection must not
 * reach this, because the offer store treats a definite refusal as proof that
 * nothing was bought — and a purchase whose reply never arrived proves nothing
 * of the kind.
 */
class AmazonLabelPurchaseException extends CarrierException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct('Amazon', $message, $previous);
    }
}
