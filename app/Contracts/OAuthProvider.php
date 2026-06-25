<?php

namespace App\Contracts;

interface OAuthProvider
{
    /**
     * Unique provider key, e.g. 'shopify', 'amazon'.
     */
    public function getKey(): string;

    /**
     * Human-readable name for UI display.
     */
    public function getDisplayName(): string;

    /**
     * Which auth flows this provider supports.
     *
     * @return array<string> e.g. ['client_credentials', 'authorization_code']
     */
    public function getSupportedAuthModes(): array;

    /**
     * Revoke the token with the provider. Best-effort; noop if unsupported.
     */
    public function revokeToken(string $accessToken): void;
}
