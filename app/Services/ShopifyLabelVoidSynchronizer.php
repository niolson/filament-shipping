<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Models\AuditLog;
use App\Models\Package;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Brings Shopify-side label voids back into PolyBag.
 *
 * A Shopify Shipping label can only be voided in the Shopify admin — the Admin
 * API has no mutation for it — so a packer who voids one there would otherwise
 * leave PolyBag holding a shipped package with a dead tracking number and a
 * label that will never scan. Polling is the only way to find out: Shopify
 * publishes no webhook for a voided label, only `FULFILLMENTS_UPDATE`, which
 * would need a public callback URL that an on-prem install may not have.
 */
class ShopifyLabelVoidSynchronizer
{
    public function __construct(
        private readonly ShopifyShippingLabelService $labelService,
    ) {}

    /**
     * Check every live Shopify-shipped package and un-ship the voided ones.
     *
     * @return array{checked: int, voided: int, failed: int}
     */
    public function sync(?int $limit = null): array
    {
        $packages = $this->candidates($limit);
        $voided = 0;
        $failed = 0;

        foreach ($packages as $package) {
            try {
                if (! $this->labelService->isVoidedInShopify($package)) {
                    continue;
                }

                $this->applyVoid($package);
                $voided++;
            } catch (\Exception $e) {
                $failed++;

                logger()->warning('Shopify label void check failed', [
                    'package_id' => $package->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['checked' => $packages->count(), 'voided' => $voided, 'failed' => $failed];
    }

    /**
     * Packages whose Shopify label could still be voided.
     *
     * Bounded by how recently the package shipped, not by whether it was
     * delivered. Shopify Shipping labels carry no tracking updates back into
     * PolyBag, so `delivered_at` never fills in for them and a delivered-based
     * filter would keep every Shopify label ever bought in the poll forever.
     *
     * @return Collection<int, Package>
     */
    public function candidates(?int $limit = null): Collection
    {
        $days = (int) config('services.shopify.label_void_check_days', 30);

        return Package::query()
            ->where('postage_source', PostageSource::PostageDataSource)
            ->whereHas(
                'postageDataSource',
                fn (Builder $query): Builder => $query->where('source_type', ShopifySource::class),
            )
            ->where('status', PackageStatus::Shipped)
            ->whereNotNull('tracking_number')
            ->where('shipped_at', '>=', now()->subDays($days))
            ->whereNull('delivered_at')
            ->with('shipment.dataSource')
            ->when($limit !== null, fn (Builder $query): Builder => $query->limit($limit))
            ->get();
    }

    /**
     * Reverse the shipment locally, leaving the package ready to ship again.
     */
    private function applyVoid(Package $package): void
    {
        $trackingNumber = $package->tracking_number;

        // Drop the Shopify label identifiers along with the shipping data. They
        // are what ShopifyShippingLabelService uses to recover a half-finished
        // purchase, and a voided label must never be recovered — re-shipping
        // this package has to buy a new one.
        $package->metadata = collect($package->metadata ?? [])
            ->except([
                'shopify_shipping_label_id',
                'shopify_purchase_result_id',
                'shopify_label_document_url',
                'shopify_customs_form_url',
            ])
            ->all();
        $package->save();

        $package->clearShipping();

        AuditLog::record(
            action: AuditAction::PackageCancelled,
            auditable: $package,
            metadata: [
                'reason' => 'Label voided in Shopify',
                'tracking_number' => $trackingNumber,
            ],
        );

        logger()->info('Shopify label voided outside PolyBag; package returned to unshipped', [
            'package_id' => $package->id,
            'tracking_number' => $trackingNumber,
        ]);
    }
}
