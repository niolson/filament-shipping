<?php

namespace App\Http\Integrations\Google;

use Saloon\Http\Connector;

/**
 * Direct-to-Google connector, used whenever no OAuth broker is configured —
 * local development, and self-hosted installs, where it is the production
 * path. Hosted traffic routes through GoogleAddressValidationProxyConnector
 * instead, for per-tenant metering.
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
