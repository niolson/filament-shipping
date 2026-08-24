<?php

namespace App\Http\Integrations\Concerns;

use App\Services\SettingsService;
use DateTimeImmutable;
use Saloon\Contracts\OAuthAuthenticator;
use Saloon\Http\Auth\AccessTokenAuthenticator;
use Saloon\Http\Connector;

/**
 * Shared token-cache plumbing for Saloon connectors that authenticate via OAuth2.
 *
 * Provides the cache-key namespacing and authenticator serialization helpers.
 * Each connector implements getAuthenticatedConnector() itself, since caching
 * is keyed per CarrierAccount and the credential source differs by carrier.
 *
 * @phpstan-require-extends Connector
 */
trait HasCachedAuthentication
{
    /**
     * Get the cache key for storing the authenticator.
     */
    abstract protected static function getAuthenticatorCacheKey(): string;

    /**
     * Suffix that namespaces a token cache key by carrier environment.
     *
     * Access tokens are signed by the environment that minted them, so a
     * sandbox token presented to production (or vice versa) is rejected with
     * an "invalid token signature" 401. Keying the cache by environment keeps
     * both tokens valid and independently cached across a sandbox_mode flip.
     */
    protected static function sandboxCacheSuffix(): string
    {
        return app(SettingsService::class)->get('sandbox_mode', false) ? '_sandbox' : '';
    }

    public static function serializeAuthenticator(OAuthAuthenticator $authenticator): array
    {
        return [
            'access_token' => $authenticator->getAccessToken(),
            'refresh_token' => $authenticator->getRefreshToken(),
            'expires_at' => $authenticator->getExpiresAt()?->getTimestamp(),
        ];
    }

    public static function deserializeAuthenticator(array $data): AccessTokenAuthenticator
    {
        return new AccessTokenAuthenticator(
            $data['access_token'],
            $data['refresh_token'] ?? null,
            isset($data['expires_at']) ? new DateTimeImmutable('@'.$data['expires_at']) : null,
        );
    }
}
