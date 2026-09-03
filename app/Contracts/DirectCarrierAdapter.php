<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Models\Package;

/**
 * A carrier we hold an account with: it quotes, it sells, it *is* the carrier,
 * and it voids and tracks the labels it sold us.
 *
 * The three roles ADR-0002 decision 7 pulled apart coincide here, and only here.
 * That coincidence is what made the single bundled interface look right for as
 * long as it did — so this composite states it as a fact about direct carriers
 * rather than letting it be assumed of every adapter.
 *
 * Voiding and tracking are declared here, not on {@see CarrierAdapterInterface},
 * because they are reached through the postage source: for a directly-bought
 * label `CarrierAccountPostageSource` resolves the adapter and calls these.
 *
 * Quoting comes from {@see AsyncRateQuoting}, which is not exclusive to direct
 * carriers — any source with a rate API implements it.
 */
interface DirectCarrierAdapter extends AsyncRateQuoting, CarrierAdapterInterface, CarrierPolicy
{
    /**
     * Cancel/void a label bought on our account with this carrier.
     */
    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse;

    /**
     * Fetch the latest tracking data for a label bought on our account.
     *
     * Callers reach this through the postage source, which checks
     * {@see CarrierPolicy::supportsTracking()} first — the entitlement to ask at
     * all follows the account that bought the postage.
     */
    public function trackShipment(Package $package): TrackShipmentResponse;
}
