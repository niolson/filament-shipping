<?php

namespace App\Services\PostageSources;

use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\PostageSource;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use App\Services\ShipmentImport\Sources\ShopifySource;

/**
 * Routes the operations that belong to whoever *bought* a label.
 *
 * `packages.carrier` used to answer this by standing in for both the carrier of
 * record and the adapter to call. ADR-0002 split those: a Shopify-bought parcel
 * now records USPS as its carrier, so asking the carrier "who voids this?"
 * would reach `UspsAdapter` and try to void a label on an account that never
 * bought it. Voiding, manifesting and tracking follow the postage source
 * instead.
 *
 * Tracking is **not** a fallback chain. When the postage source cannot answer,
 * that is the answer — trying the carrier next would be a request we hold no
 * entitlement to make.
 */
class PostageSourceDispatcher
{
    public function __construct(
        private readonly CarrierRegistry $carrierRegistry,
        private readonly ShopifyPostageSource $shopify,
    ) {}

    /**
     * @throws \InvalidArgumentException when a direct purchase names a carrier with no adapter
     */
    public function voidLabel(Package $package): CancelResponse
    {
        if ($package->postage_source === PostageSource::PostageDataSource) {
            return $this->isShopify($package)
                ? $this->shopify->voidLabel($package)
                : CancelResponse::failure('This label was bought through a sales channel PolyBag cannot void through.');
        }

        return $this->carrierRegistry
            ->get((string) $package->carrier)
            ->cancelShipment((string) $package->tracking_number, $package);
    }

    public function trackShipment(Package $package): TrackShipmentResponse
    {
        if ($package->postage_source === PostageSource::PostageDataSource) {
            return $this->isShopify($package)
                ? $this->shopify->trackShipment($package)
                : TrackShipmentResponse::unsupported('Tracking is not supported for this postage source.');
        }

        $adapter = $this->carrierAdapter($package);

        if (! $adapter) {
            return TrackShipmentResponse::failure("Unknown carrier: {$package->carrier}");
        }

        return $adapter->supportsTracking()
            ? $adapter->trackShipment($package)
            : TrackShipmentResponse::unsupported();
    }

    /**
     * Whether we could put this package on a manifest of our own.
     *
     * Postage source first: a SCAN form is a claim that we tendered these
     * parcels on our own account, which is false for channel-bought postage
     * whatever carrier is carrying it.
     */
    public function supportsManifest(Package $package): bool
    {
        if ($package->postage_source !== PostageSource::CarrierAccount) {
            return false;
        }

        return $this->carrierAdapter($package)?->supportsManifest() ?? false;
    }

    private function carrierAdapter(Package $package): ?CarrierAdapterInterface
    {
        if (! $package->carrier || ! $this->carrierRegistry->has($package->carrier)) {
            return null;
        }

        return $this->carrierRegistry->get($package->carrier);
    }

    private function isShopify(Package $package): bool
    {
        $package->loadMissing('postageDataSource');

        return $package->postageDataSource?->source_type === ShopifySource::class;
    }
}
