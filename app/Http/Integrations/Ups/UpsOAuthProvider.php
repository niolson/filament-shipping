<?php

namespace App\Http\Integrations\Ups;

use App\Contracts\OAuthProvider;

class UpsOAuthProvider implements OAuthProvider
{
    public function getKey(): string
    {
        return 'ups';
    }

    public function getDisplayName(): string
    {
        return 'UPS';
    }

    public function getSupportedAuthModes(): array
    {
        return ['client_credentials', 'authorization_code'];
    }

    public function revokeToken(string $accessToken): void
    {
        // UPS does not provide a token revocation endpoint
    }
}
