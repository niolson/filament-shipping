<?php

namespace App\Services\PostageSources;

use App\Contracts\PostageSourceOperations;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Models\Package;

/**
 * A label bought through a sales channel we have no postage integration for.
 *
 * Amazon Buy Shipping lands here until its own implementation exists, as does
 * any package whose postage data source has since been re-pointed at a driver
 * that never sold it. Every answer is a refusal, and deliberately not a
 * delegation: falling through to the carrier would send our own USPS account a
 * label somebody else bought — the exact conflation this seam removes.
 */
class UnrecognizedPostageSource implements PostageSourceOperations
{
    public function voidLabel(Package $package): CancelResponse
    {
        return CancelResponse::failure('This label was bought through a sales channel PolyBag cannot void through.');
    }

    public function trackShipment(Package $package): TrackShipmentResponse
    {
        return TrackShipmentResponse::unsupported('Tracking is not supported for this postage source.');
    }

    public function supportsPackageManifest(Package $package): bool
    {
        return false;
    }
}
