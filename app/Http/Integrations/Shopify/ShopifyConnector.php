<?php

namespace App\Http\Integrations\Shopify;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Saloon\Http\Connector;

class ShopifyConnector extends Connector
{
    public ?int $tries = 3;

    public ?int $retryInterval = 500;

    public ?bool $useExponentialBackoff = true;

    private const CACHE_KEY_PREFIX = 'shopify_access_token_';

    public function __construct(
        private readonly string $shopDomain,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $apiVersion = '2025-01',
        private readonly ?string $accessToken = null,
    ) {}

    public static function fromConfig(): self
    {
        return self::fromSettings([]);
    }

    /**
     * Build from a per-source config array. All values (shop_domain, client_id,
     * client_secret, access_token) are per-source and stored on the DataSource.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function fromSettings(array $settings): self
    {
        // Manual access_token takes priority over OAuth token; both are per-source.
        $accessToken = filled($settings['access_token'] ?? null)
            ? (string) $settings['access_token']
            : (filled($settings['oauth_access_token'] ?? null) ? (string) $settings['oauth_access_token'] : null);

        return new self(
            shopDomain: (string) ($settings['shop_domain'] ?? ''),
            clientId: (string) ($settings['client_id'] ?? ''),
            clientSecret: (string) ($settings['client_secret'] ?? ''),
            apiVersion: (string) ($settings['api_version'] ?? config('services.shopify.api_version', '2025-01')),
            accessToken: $accessToken,
        );
    }

    public function resolveBaseUrl(): string
    {
        return "https://{$this->shopDomain}/admin/api/{$this->apiVersion}/graphql.json";
    }

    protected function defaultHeaders(): array
    {
        return [
            'X-Shopify-Access-Token' => $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Get a valid access token. Per-source injected tokens take priority,
     * then global OAuth token, then client credentials flow.
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $cacheKey = self::CACHE_KEY_PREFIX.md5($this->shopDomain);

        return Cache::get($cacheKey) ?? $this->requestNewToken($cacheKey);
    }

    private function requestNewToken(string $cacheKey): string
    {
        $response = Http::asForm()->post(
            "https://{$this->shopDomain}/admin/oauth/access_token",
            [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException(
                'Failed to obtain Shopify access token: '.$response->body()
            );
        }

        $data = $response->json();
        $token = $data['access_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? 86399;

        if (! $token) {
            throw new RuntimeException(
                'Shopify token response missing access_token: '.$response->body()
            );
        }

        Cache::put($cacheKey, $token, $expiresIn - 600);

        return $token;
    }
}
