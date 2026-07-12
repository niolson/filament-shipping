<?php

namespace App\Http\Integrations\Google;

use Saloon\Http\Connector;

/**
 * Direct-to-Google connector, used only when polybag-connect isn't
 * configured (local development). Production traffic always routes
 * through GoogleAddressValidationProxyConnector for per-tenant metering.
 */
class GoogleAddressValidationConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        return rtrim(config('services.google_address_validation.base_url'), '/');
    }

    protected function defaultHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => config('services.google_address_validation.api_key'),
        ];
    }
}
