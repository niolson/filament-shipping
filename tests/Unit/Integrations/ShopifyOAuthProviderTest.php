<?php

use App\Http\Integrations\Shopify\ShopifyOAuthProvider;

it('returns the correct provider key', function (): void {
    $provider = new ShopifyOAuthProvider;

    expect($provider->getKey())->toBe('shopify');
    expect($provider->getDisplayName())->toBe('Shopify');
});

it('supports both auth modes', function (): void {
    $provider = new ShopifyOAuthProvider;

    expect($provider->getSupportedAuthModes())->toBe(['client_credentials', 'authorization_code']);
});

it('revokeToken is a no-op for Shopify', function (): void {
    $provider = new ShopifyOAuthProvider;

    $provider->revokeToken('shpat_token');

    expect(true)->toBeTrue();
});
