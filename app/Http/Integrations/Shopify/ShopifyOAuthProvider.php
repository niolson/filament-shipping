<?php

namespace App\Http\Integrations\Shopify;

use App\Contracts\OAuthProvider;

class ShopifyOAuthProvider implements OAuthProvider
{
    public function getKey(): string
    {
        return 'shopify';
    }

    public function getDisplayName(): string
    {
        return 'Shopify';
    }

    public function getSupportedAuthModes(): array
    {
        return ['client_credentials', 'authorization_code'];
    }

    public function revokeToken(string $accessToken): void
    {
        // Shopify has no token revocation endpoint; local cleanup only
    }
}
