<?php

namespace App\Exceptions\Carriers;

use Throwable;

/**
 * A Shopify Shipping label purchase that failed with a reason worth showing the
 * packer. Shopify reports failures in three places — GraphQL errors, the
 * mutation's userErrors, and the async result's errors — and this carries
 * whichever one applies.
 */
class ShopifyLabelPurchaseException extends CarrierException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct('Shopify', $message, $previous);
    }
}
