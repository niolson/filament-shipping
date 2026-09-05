<?php

namespace App\Services\ShipmentImport;

use App\Exceptions\MissingAmazonOrderItemsException;
use App\Models\Package;
use App\Models\PackageItem;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Support\Collection;

/**
 * What was packed, named the way Amazon names it.
 *
 * Two callers want the same list for opposite reasons, which is why this is a
 * class rather than a private method on either of them:
 * {@see PackageExportService} sends it to `confirmShipment` *after* a label was
 * bought elsewhere, and {@see AmazonBuyShippingAdapter} sends it to `getRates`
 * and `purchaseShipment` *to* buy one. Both are the identifiers Amazon issued
 * on import, read off `shipment_items.source_item_id`, and a second copy of the
 * completeness rule below would be the copy that goes stale.
 *
 * The rule is that the list is all-or-nothing. A package half of whose items
 * Amazon can identify is not a partially-describable shipment; it is a package
 * that was imported before item IDs were being recorded, or one packed against
 * a shipment item that has since been replaced. Confirming or rating it would
 * tell Amazon the parcel contains less than it does.
 */
class AmazonOrderItems
{
    /**
     * The `confirmShipment` shape: identity and count, no valuation.
     *
     * @return list<array{orderItemId: string, quantity: int, transparencyCodes?: array<int, string>}>
     *
     * @throws MissingAmazonOrderItemsException when a packed item has no Amazon order item ID
     */
    public function forPackage(Package $package): array
    {
        return $this->identifiedPackedItems($package)
            ->map(function (PackageItem $item): array {
                $orderItem = [
                    'orderItemId' => (string) $item->shipmentItem->source_item_id,
                    'quantity' => (int) $item->quantity,
                ];

                if (! empty($item->transparency_codes)) {
                    $orderItem['transparencyCodes'] = $item->transparency_codes;
                }

                return $orderItem;
            })
            ->values()
            ->all();
    }

    /**
     * The Shipping v2 `Item` shape: the same items, valued and weighed.
     *
     * Rating needs what confirmation does not — Amazon prices some services off
     * declared contents, and an international label is built from them. The
     * identity half is identical and comes from the same rule, so the two
     * shapes cannot end up describing different parcels.
     *
     * @return list<array{itemValue: array{value: float, unit: string}, description: string, itemIdentifier: string, quantity: int, weight: array{value: float, unit: string}}>
     *
     * @throws MissingAmazonOrderItemsException when a packed item has no Amazon order item ID
     */
    public function shippingItemsFor(Package $package, string $currency = 'USD'): array
    {
        $package->loadMissing('packageItems.product');

        return $this->identifiedPackedItems($package)
            ->map(fn (PackageItem $item): array => [
                'itemValue' => [
                    'value' => round((float) ($item->shipmentItem->value ?? 0), 2),
                    'unit' => $currency,
                ],
                'description' => (string) ($item->product->description ?? $item->product->name ?? 'Merchandise'),
                'itemIdentifier' => (string) $item->shipmentItem->source_item_id,
                'quantity' => (int) $item->quantity,
                'weight' => [
                    // A product with no weight on file still has to weigh
                    // something: Amazon rejects a zero-weight item, and the
                    // parcel's own weight — scanned, not inferred — is what the
                    // rate is actually computed from.
                    'value' => round(max(0.01, (float) ($item->product->weight ?? 0.01)), 2),
                    'unit' => 'POUND',
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * The Amazon order this package's shipment came from, or null.
     *
     * Kept beside the items because it is the other half of the same identity:
     * an order ID with no items is as unusable to `getRates` as items with no
     * order. Read from the shipment metadata {@see AmazonSource} writes on
     * import.
     */
    public function orderIdFor(Package $package): ?string
    {
        $package->loadMissing('shipment');

        $orderId = $package->shipment?->metadata['amazon_order_id'] ?? null;

        return filled($orderId) ? (string) $orderId : null;
    }

    /**
     * Every packed item, with the assurance that Amazon can name all of them.
     *
     * @return Collection<int, PackageItem>
     *
     * @throws MissingAmazonOrderItemsException
     */
    private function identifiedPackedItems(Package $package): Collection
    {
        $package->loadMissing('packageItems.shipmentItem');

        $packed = $package->packageItems
            ->filter(fn (PackageItem $item): bool => (int) $item->quantity > 0);

        $identified = $packed->filter(
            fn (PackageItem $item): bool => filled($item->shipmentItem?->source_item_id)
        );

        if ($identified->count() !== $packed->count()) {
            throw new MissingAmazonOrderItemsException(
                'Amazon shipment confirmation requires an order item ID for every packed item.'
            );
        }

        return $identified;
    }
}
