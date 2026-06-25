<?php

namespace App\Http\Integrations\Amazon;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Saloon\Http\Connector;

class AmazonSpApiConnector extends Connector
{
    public ?int $tries = 3;

    public ?int $retryInterval = 500;

    public ?bool $useExponentialBackoff = true;

    private const CACHE_KEY_PREFIX = 'amazon_sp_api_access_token_';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $sandboxUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
    ) {}

    public static function fromConfig(): self
    {
        return self::fromSettings([]);
    }

    /**
     * Build from a per-source config array. All credentials (client_id, client_secret,
     * refresh_token) are per-source and stored on the DataSource.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function fromSettings(array $settings): self
    {
        return new self(
            baseUrl: config('services.amazon.base_url') ?? 'https://sellingpartnerapi-na.amazon.com',
            sandboxUrl: config('services.amazon.sandbox_url') ?? 'https://sandbox.sellingpartnerapi-na.amazon.com',
            clientId: (string) ($settings['client_id'] ?? ''),
            clientSecret: (string) ($settings['client_secret'] ?? ''),
            refreshToken: (string) ($settings['refresh_token'] ?? ''),
        );
    }

    public function resolveBaseUrl(): string
    {
        $sandbox = app(SettingsService::class)->get('sandbox_mode', false);

        return $sandbox ? $this->sandboxUrl : $this->baseUrl;
    }

    protected function defaultHeaders(): array
    {
        return [
            'x-amz-access-token' => $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ];
    }

    private function getAccessToken(): string
    {
        $cacheKey = self::CACHE_KEY_PREFIX.md5($this->refreshToken);

        return Cache::get($cacheKey) ?? $this->requestNewToken($cacheKey);
    }

    private function requestNewToken(string $cacheKey): string
    {
        $response = Http::asForm()->post(
            'https://api.amazon.com/auth/o2/token',
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException(
                'Failed to obtain Amazon SP-API access token: '.$response->body()
            );
        }

        $data = $response->json();
        $token = $data['access_token'] ?? null;

        if (! $token) {
            throw new RuntimeException(
                'Amazon token response missing access_token: '.$response->body()
            );
        }

        // Token lasts 1 hour; cache for 50 minutes
        Cache::put($cacheKey, $token, 3000);

        return $token;
    }
}
