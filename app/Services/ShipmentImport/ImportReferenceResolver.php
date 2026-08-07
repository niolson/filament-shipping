<?php

namespace App\Services\ShipmentImport;

use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\Client;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Services\ClientContext;

class ImportReferenceResolver
{
    /** @var array<string, int> */
    private array $channelCache = [];

    /** @var array<string, ?int> */
    private array $shippingMethodCache = [];

    /** @var array<string, int> */
    private array $productCache = [];

    private ?int $warmedClientId = null;

    public function warm(?Client $client = null): void
    {
        $client ??= app(ClientContext::class)->default();

        $this->channelCache = [];
        $this->shippingMethodCache = [];
        $this->productCache = [];

        ChannelAlias::where('client_id', $client->id)->get()->each(function (ChannelAlias $alias): void {
            $this->channelCache[$alias->reference] = $alias->channel_id;
        });

        Channel::pluck('id')->each(function (int $id): void {
            $this->channelCache[(string) $id] = $id;
        });

        ShippingMethodAlias::where('client_id', $client->id)->get()->each(function (ShippingMethodAlias $alias): void {
            $this->shippingMethodCache[$alias->reference] = $alias->shipping_method_id;
        });

        ShippingMethod::pluck('id')->each(function (int $id): void {
            $this->shippingMethodCache[(string) $id] = $id;
        });

        Product::where('client_id', $client->id)->pluck('id', 'sku')->each(function (int $id, string $sku): void {
            $this->productCache[$sku] = $id;
        });

        $this->warmedClientId = $client->id;
    }

    public function shippingMethodIdFor(array $data, ?Client $client = null): ?int
    {
        $reference = $data['shipping_method_id'] ?? null;

        if (! $reference) {
            return null;
        }

        $this->ensureWarm($client);

        return $this->shippingMethodCache[(string) $reference] ?? null;
    }

    public function channelIdFor(array $data, ?Client $client = null): ?int
    {
        $reference = $data['channel_id'] ?? null;

        if (! $reference) {
            return null;
        }

        $this->ensureWarm($client);

        return $this->channelCache[(string) $reference] ?? null;
    }

    /**
     * @return array{id: int|null, created: bool, updated: bool}
     */
    public function productIdFor(array $itemData, ?Client $client = null): array
    {
        $client ??= app(ClientContext::class)->default();
        $sku = $itemData['sku'] ?? null;

        if (! $sku) {
            return ['id' => null, 'created' => false, 'updated' => false];
        }

        if (config('shipment-import.behavior.auto_update_products', true)) {
            $updateData = array_filter([
                'name' => $itemData['name'] ?? $sku,
                'description' => $itemData['description'] ?? null,
                'barcode' => $itemData['barcode'] ?? null,
                'weight' => $itemData['weight'] ?? null,
            ], fn ($value) => $value !== null);

            $product = Product::firstOrNew([
                'client_id' => $client->id,
                'sku' => $sku,
            ]);

            if (($itemData['_fill_missing_barcode_only'] ?? false) && filled($product->barcode)) {
                unset($updateData['barcode']);
            }

            $product->fill(array_merge($updateData, ['active' => true]))->save();

            $this->productCache[$sku] = $product->id;

            return [
                'id' => $product->id,
                'created' => $product->wasRecentlyCreated,
                'updated' => ! $product->wasRecentlyCreated && $product->wasChanged(),
            ];
        }

        $this->ensureWarm($client);

        return [
            'id' => $this->productCache[$sku] ?? null,
            'created' => false,
            'updated' => false,
        ];
    }

    private function ensureWarm(?Client $client = null): void
    {
        $client ??= app(ClientContext::class)->default();

        if ($this->warmedClientId !== $client->id) {
            $this->warm($client);
        }
    }
}
