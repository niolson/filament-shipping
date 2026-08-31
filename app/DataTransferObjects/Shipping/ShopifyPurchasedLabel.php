<?php

namespace App\DataTransferObjects\Shipping;

/**
 * A label that Shopify Shipping has finished purchasing.
 *
 * Shopify chooses the file format from the shop's own admin setting — the API
 * offers no way to request one — so `labelFormat` reports what actually came
 * back rather than what was asked for.
 */
readonly class ShopifyPurchasedLabel
{
    public function __construct(
        public string $shippingLabelId,
        public ?string $trackingNumber,
        public ?string $trackingCompany,
        public ?string $labelData,
        public string $labelFormat,
        // The customs form is a separate document Shopify hosts. PolyBag has
        // nowhere to print a second document from, so the URL is kept for an
        // operator to open rather than the file being downloaded and dropped.
        public ?string $customsFormUrl = null,
        // Kept so a label that was bought but could not be downloaded is still
        // reachable — by a retry, or by hand from the Shopify admin.
        public ?string $labelDocumentUrl = null,
    ) {}
}
