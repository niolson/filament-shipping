<?php

namespace App\Services\PostageSources;

use App\Contracts\PostageSourceOperations;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\PostageSource;
use App\Models\Package;
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
 * Resolution is the whole job. Every method below picks one
 * {@see PostageSourceOperations} from the `postage_source` discriminator and
 * asks it — there is no per-operation branching left, and adding a source means
 * adding an implementation and an arm here, not a case in three `match`
 * expressions.
 *
 * Tracking is **not** a fallback chain. When the postage source cannot answer,
 * that is the answer — trying the carrier next would be a request we hold no
 * entitlement to make.
 */
class PostageSourceDispatcher
{
    public function __construct(
        private readonly CarrierAccountPostageSource $carrierAccount,
        private readonly ShopifyPostageSource $shopify,
        private readonly UnrecognizedPostageSource $unrecognized,
    ) {}

    /**
     * @throws \InvalidArgumentException when a direct purchase names a carrier with no adapter
     */
    public function voidLabel(Package $package): CancelResponse
    {
        return $this->resolve($package)->voidLabel($package);
    }

    public function trackShipment(Package $package): TrackShipmentResponse
    {
        return $this->resolve($package)->trackShipment($package);
    }

    /**
     * Whether we could put this package on a manifest of our own.
     *
     * Postage source first: a SCAN form is a claim that we tendered these
     * parcels on our own account, which is false for channel-bought postage
     * whatever carrier is carrying it. The carrier-level question — does this
     * carrier manifest at all? — is `CarrierPolicy::supportsCarrierManifest()`,
     * and only the direct arm goes on to ask it.
     */
    public function supportsPackageManifest(Package $package): bool
    {
        return $this->resolve($package)->supportsPackageManifest($package);
    }

    /**
     * The one place the discriminator is read.
     */
    private function resolve(Package $package): PostageSourceOperations
    {
        if ($package->postage_source !== PostageSource::PostageDataSource) {
            return $this->carrierAccount;
        }

        $package->loadMissing('postageDataSource');

        return $package->postageDataSource?->source_type === ShopifySource::class
            ? $this->shopify
            : $this->unrecognized;
    }
}
