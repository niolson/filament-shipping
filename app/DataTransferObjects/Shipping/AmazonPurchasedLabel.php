<?php

namespace App\DataTransferObjects\Shipping;

use App\Services\AmazonBuyShippingService;

/**
 * What `purchaseShipment` hands back.
 *
 * Notably not a carrier, a service or a price: `PurchaseShipmentResult` carries
 * a shipment ID, documents and a promise, and nothing else. Those three come
 * off the offer that was spent, which is the right place for them anyway —
 * the offer is the purchase authority and the rate is display data.
 *
 * @see AmazonBuyShippingService
 */
readonly class AmazonPurchasedLabel
{
    /**
     * @param  string  $shipmentId  Amazon's identifier for the shipment — the only thing that can cancel it, and what tells the channel export the order is already confirmed.
     */
    public function __construct(
        public string $shipmentId,
        public ?string $trackingId = null,
        public ?string $labelData = null,
        public string $labelFormat = 'pdf',
        public ?int $labelDpi = null,
    ) {}
}
