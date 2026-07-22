<?php

namespace App\Services\Carriers\Concerns;

use App\Models\Carrier;
use App\Models\CarrierAccount;

/**
 * Resolves the carrier account for a shipment and reports configuration status.
 *
 * The carrier row is looked up by getCarrierName() (declared on
 * CarrierAdapterInterface), so each adapter's declared name stays the single
 * source of truth for both resolution and the DB lookup.
 */
trait ResolvesCarrierAccount
{
    abstract public function getCarrierName(): string;

    private function resolveAccount(?int $locationId, ?int $clientId = null): ?CarrierAccount
    {
        $carrierId = Carrier::where('name', $this->getCarrierName())->value('id');

        return $carrierId
            ? CarrierAccount::resolveForShipment($carrierId, $locationId, $clientId)->first()
            : null;
    }

    public function isConfigured(): bool
    {
        $carrierId = Carrier::where('name', $this->getCarrierName())->value('id');

        return $carrierId !== null
            && CarrierAccount::active()
                ->where('carrier_id', $carrierId)
                ->with('carrier')
                ->get()
                ->contains(fn (CarrierAccount $account): bool => $account->hasUsableCredentials());
    }
}
