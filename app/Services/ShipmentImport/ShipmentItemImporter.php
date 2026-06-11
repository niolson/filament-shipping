<?php

namespace App\Services\ShipmentImport;

use App\Models\DataSource;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use Illuminate\Support\Collection;

class ShipmentItemImporter
{
    public function __construct(
        private readonly ImportReferenceResolver $references,
    ) {}

    /**
     * Import pre-fetched item rows for a shipment. Items are fetched upstream
     * (before the batch write) so they can feed the source checksum.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{items_created: int, items_updated: int, products_created: int, products_updated: int}
     */
    public function import(Shipment $shipment, Collection $items, DataSource $record): array
    {
        if (! $this->isEnabledFor($record)) {
            return $this->emptyStats();
        }

        $stats = $this->emptyStats();

        foreach ($items as $itemData) {
            $product = $this->references->productIdFor($itemData, $shipment->client);
            $productId = $product['id'];

            if (! $productId) {
                continue;
            }

            $shipmentItem = ShipmentItem::updateOrCreate(
                [
                    'shipment_id' => $shipment->id,
                    'product_id' => $productId,
                ],
                [
                    'barcode' => $itemData['barcode'] ?? null,
                    'quantity' => $itemData['quantity'] ?? 1,
                    'value' => $itemData['value'] ?? null,
                    'description' => $itemData['description'] ?? null,
                    'transparency' => $itemData['transparency'] ?? false,
                ]
            );

            if ($shipmentItem->wasRecentlyCreated) {
                $stats['items_created']++;
            } else {
                $stats['items_updated']++;
            }

            if ($product['created']) {
                $stats['products_created']++;
            } elseif ($product['updated']) {
                $stats['products_updated']++;
            }
        }

        return $stats;
    }

    public function isEnabledFor(DataSource $record): bool
    {
        return (bool) ($record->settings['shipment_items_enabled'] ?? true);
    }

    /**
     * @return array{items_created: int, items_updated: int, products_created: int, products_updated: int}
     */
    private function emptyStats(): array
    {
        return [
            'items_created' => 0,
            'items_updated' => 0,
            'products_created' => 0,
            'products_updated' => 0,
        ];
    }
}
