<?php

namespace App\Http\Integrations\Gotenberg;

use RuntimeException;
use Saloon\Http\Connector;

class GotenbergConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        $url = config('services.gotenberg.url');

        if (blank($url)) {
            throw new RuntimeException('Gotenberg is not configured. Set GOTENBERG_URL in your environment.');
        }

        return rtrim((string) $url, '/');
    }
}
