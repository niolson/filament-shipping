<?php

namespace App\Services\PostageSources;

use App\Contracts\PostageOfferSource;
use App\Contracts\PostageSourceOperations;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\PostageSource;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\CarrierRegistry;
use App\Services\ShipmentImport\Sources\AmazonSource;
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
        private readonly AmazonPostageSource $amazon,
        private readonly UnrecognizedPostageSource $unrecognized,
        private readonly CarrierRegistry $carrierRegistry,
    ) {}

    /**
     * Who has to be asked to buy this offer.
     *
     * The other end of the same discriminator, and the reason quoting and
     * purchasing could not move onto this seam until an offer carried the
     * source instance it came from (`postage-source-split/08`'s deferral, now
     * discharged). Purchase used to dispatch on the carrier *name*, which is
     * only correct when the carrier of record and the seller are the same
     * thing. They are not for channel postage: an Amazon offer carried by
     * OnTrac must be bought from Amazon, and looking up "OnTrac" finds a direct
     * adapter we do not have and hold no account with.
     *
     * Null is a refusal, never a fallback. A `postage_data_source` offer whose
     * source has since been re-pointed at a driver that sells no postage has no
     * seller, and reaching for the carrier instead would buy the label on an
     * account of ours that never quoted the price.
     */
    public function sellerFor(ShippingOffer $offer): ?PostageOfferSource
    {
        if ($offer->postage_source !== PostageSource::PostageDataSource) {
            return $this->carrierRegistry->quotingAdapterFor($offer->carrier);
        }

        $offer->loadMissing('postageDataSource');

        // Shopify is deliberately absent: it sells a blind purchase and issues
        // no offer at all, so an offer naming a Shopify source is a row that
        // could not have been written. See `BlindPurchaseSource`.
        return $offer->postageDataSource?->source_type === AmazonSource::class
            ? $this->carrierRegistry->quotingAdapterFor(AmazonBuyShippingAdapter::SOURCE_NAME)
            : null;
    }

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

        return match ($package->postageDataSource?->source_type) {
            ShopifySource::class => $this->shopify,
            AmazonSource::class => $this->amazon,
            default => $this->unrecognized,
        };
    }
}
