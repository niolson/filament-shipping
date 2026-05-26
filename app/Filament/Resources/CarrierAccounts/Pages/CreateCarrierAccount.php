<?php

namespace App\Filament\Resources\CarrierAccounts\Pages;

use App\Filament\Resources\CarrierAccounts\CarrierAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCarrierAccount extends CreateRecord
{
    protected static string $resource = CarrierAccountResource::class;

    /** @var array<string, string> Secrets entered at create time, applied in afterCreate(). */
    private array $pendingSecrets = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Virtual account_number fields for FedEx and UPS
        foreach (['fedex_account_number', 'ups_account_number'] as $field) {
            if (array_key_exists($field, $data)) {
                $data['credentials'] = array_merge(
                    $data['credentials'] ?? [],
                    ['account_number' => $data[$field]]
                );
                unset($data[$field]);
            }
        }

        // Virtual secret credential fields — stash filled values for afterCreate(),
        // strip from $data so the model create doesn't see unknown keys.
        $secretMappings = [
            'usps_adv_client_id' => 'client_id',
            'usps_adv_client_secret' => 'client_secret',
            'fedex_adv_api_key' => 'api_key',
            'fedex_adv_api_secret' => 'api_secret',
            'fedex_adv_sandbox_api_key' => 'sandbox_api_key',
            'fedex_adv_sandbox_api_secret' => 'sandbox_api_secret',
            'ups_adv_client_id' => 'client_id',
            'ups_adv_client_secret' => 'client_secret',
        ];

        foreach ($secretMappings as $formField => $secretKey) {
            if (isset($data[$formField]) && filled($data[$formField])) {
                $this->pendingSecrets[$secretKey] = $data[$formField];
            }
            unset($data[$formField]);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        // Apply any secret credentials stashed during mutateFormDataBeforeCreate().
        if (! empty($this->pendingSecrets)) {
            foreach ($this->pendingSecrets as $key => $value) {
                $this->record->mergeSecret($key, $value);
            }
            $this->record->save();
            $this->pendingSecrets = [];
        }

        // Ensure a global (null,null) scope exists so resolveForShipment() returns
        // this account when no location- or client-specific scope is configured.
        if (! $this->record->scopes()->whereNull('location_id')->whereNull('client_id')->exists()) {
            $this->record->scopes()->create(['location_id' => null, 'client_id' => null, 'rate_shop' => false]);
        }
    }
}
