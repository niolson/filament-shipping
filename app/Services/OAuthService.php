<?php

namespace App\Services;

use App\Models\CarrierAccount;
use App\Models\DataSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OAuthService
{
    public function __construct(
        private readonly OAuthProviderRegistry $registry,
    ) {}

    /**
     * Generate the broker authorization URL and store nonce in session.
     */
    public function initiateAuthorization(string $providerKey, ?int $accountId = null): string
    {
        $provider = $this->registry->get($providerKey);

        if (! in_array('authorization_code', $provider->getSupportedAuthModes())) {
            throw new RuntimeException("Provider '{$providerKey}' does not support authorization code flow.");
        }

        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        if (! $brokerUrl || ! $brokerSecret || ! $instanceId) {
            throw new RuntimeException('OAuth broker is not configured. Set OAUTH_BROKER_URL, OAUTH_BROKER_SECRET, and OAUTH_INSTANCE_ID.');
        }

        $nonce = Str::random(40);
        session()->put("oauth_state.{$providerKey}", $nonce);

        if ($accountId) {
            session()->put("oauth_account_id.{$providerKey}", $accountId);
        }

        $returnUrl = config('app.url');
        $signature = hash_hmac('sha256', "{$providerKey}:{$instanceId}:{$returnUrl}::{$nonce}", $brokerSecret);

        $params = array_filter([
            'return_url' => $returnUrl,
            'instance_id' => $instanceId,
            'nonce' => $nonce,
            'signature' => $signature,
        ]);

        return rtrim($brokerUrl, '/')."/oauth/{$providerKey}/authorize?".http_build_query($params);
    }

    /**
     * Generate the broker authorization URL for an SSO login flow.
     * The broker will redirect to /auth/sso/{provider}/receive on completion.
     */
    public function initiateSsoAuthorization(string $provider): string
    {
        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        if (! $brokerUrl || ! $brokerSecret || ! $instanceId) {
            throw new RuntimeException('OAuth broker is not configured. Set OAUTH_BROKER_URL, OAUTH_BROKER_SECRET, and OAUTH_INSTANCE_ID.');
        }

        $nonce = Str::random(40);
        session()->put("oauth_state.{$provider}", $nonce);

        $returnUrl = config('app.url');
        $returnPath = "/auth/sso/{$provider}/receive";
        $signature = hash_hmac('sha256', "{$provider}:{$instanceId}:{$returnUrl}:{$returnPath}:{$nonce}", $brokerSecret);

        $params = [
            'return_url' => $returnUrl,
            'return_path' => $returnPath,
            'instance_id' => $instanceId,
            'nonce' => $nonce,
            'signature' => $signature,
        ];

        return rtrim($brokerUrl, '/')."/oauth/{$provider}/authorize?".http_build_query($params);
    }

    /**
     * Generate the broker authorization URL for a specific DataSource.
     * Stores the DataSource ID in session so the callback can route back to it.
     */
    public function initiateAuthorizationForDataSource(string $providerKey, DataSource $importSource): string
    {
        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        if (! $brokerUrl || ! $brokerSecret || ! $instanceId) {
            throw new RuntimeException('OAuth broker is not configured. Set OAUTH_BROKER_URL, OAUTH_BROKER_SECRET, and OAUTH_INSTANCE_ID.');
        }

        $nonce = Str::random(40);
        session()->put("oauth_state.{$providerKey}", $nonce);
        session()->put("oauth_data_source_id.{$providerKey}", $importSource->id);

        $returnUrl = config('app.url');
        $signature = hash_hmac('sha256', "{$providerKey}:{$instanceId}:{$returnUrl}::{$nonce}", $brokerSecret);

        $brokerParams = $this->getBrokerParamsForDataSource($providerKey, $importSource);

        $params = array_filter([
            'return_url' => $returnUrl,
            'instance_id' => $instanceId,
            'nonce' => $nonce,
            'signature' => $signature,
            ...$brokerParams,
        ]);

        return rtrim($brokerUrl, '/')."/oauth/{$providerKey}/authorize?".http_build_query($params);
    }

    /**
     * Handle the broker redirect for a per-DataSource OAuth connection.
     * Tokens are stored in the DataSource settings JSON.
     */
    public function handleReceiveForDataSource(string $providerKey, string $transferCode, DataSource $importSource): void
    {
        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        $signature = hash_hmac('sha256', $transferCode, $brokerSecret);

        $response = Http::post(rtrim($brokerUrl, '/').'/oauth/claim', [
            'transfer_code' => $transferCode,
            'instance_id' => $instanceId,
            'signature' => $signature,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to claim tokens from broker: '.$response->body());
        }

        $data = $response->json();

        $expectedNonce = session()->pull("oauth_state.{$providerKey}");

        if (empty($expectedNonce) || ! hash_equals($expectedNonce, $data['nonce'] ?? '')) {
            throw new RuntimeException('OAuth state mismatch. Please try again.');
        }

        $accessToken = $data['access_token'] ?? null;
        if (empty($accessToken)) {
            throw new RuntimeException('No access token received from broker.');
        }

        $importSource->mergeSecret('oauth_access_token', $accessToken);

        $settings = $importSource->settings ?? [];
        $settings['oauth_connected_at'] = now()->toIso8601String();
        $settings['oauth_scopes'] = $data['extra']['scope'] ?? $data['scope'] ?? '';
        $settings['auth_mode'] = 'authorization_code';
        $importSource->settings = $settings;
        $importSource->save();

        // Bust the per-shop token cache so the new token is picked up immediately.
        $shopDomain = $settings['shop_domain'] ?? '';
        if ($shopDomain) {
            Cache::forget('shopify_access_token_'.md5($shopDomain));
        }
    }

    /**
     * Disconnect an DataSource's OAuth connection, clearing its token fields.
     */
    public function disconnectDataSource(string $providerKey, DataSource $importSource): void
    {
        // Best-effort revocation
        $token = $importSource->secret('oauth_access_token');
        if ($token && $this->registry->has($providerKey)) {
            try {
                $this->registry->get($providerKey)->revokeToken($token);
            } catch (\Throwable) {
            }
        }

        $secrets = $importSource->secret_settings ?? [];
        unset($secrets['oauth_access_token']);
        $importSource->secret_settings = $secrets ?: null;

        $settings = $importSource->settings ?? [];
        foreach (['oauth_connected_at', 'oauth_scopes', 'auth_mode'] as $key) {
            unset($settings[$key]);
        }

        $importSource->settings = $settings;
        $importSource->save();

        $shopDomain = $settings['shop_domain'] ?? '';
        if ($shopDomain) {
            Cache::forget('shopify_access_token_'.md5($shopDomain));
        }
    }

    /**
     * Check if an DataSource is connected via OAuth.
     */
    public function isDataSourceConnected(DataSource $importSource): bool
    {
        return filled($importSource->secret('oauth_access_token'));
    }

    /**
     * Build provider-specific broker params for a per-DataSource OAuth flow.
     * For Shopify this overrides the shop domain with the per-source value.
     *
     * @return array<string, string>
     */
    private function getBrokerParamsForDataSource(string $providerKey, DataSource $importSource): array
    {
        if ($providerKey === 'shopify') {
            $shopDomain = $importSource->settings['shop_domain'] ?? null;

            return array_filter([
                'shop' => $shopDomain,
                'scope' => implode(',', ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES),
            ]);
        }

        return [];
    }

    /**
     * Handle broker redirect for a per-account OAuth connection.
     * Tokens are stored in the account's secret_credentials instead of global settings.
     */
    public function handleReceiveForAccount(string $providerKey, string $transferCode, CarrierAccount $account): void
    {
        $provider = $this->registry->get($providerKey);

        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        $signature = hash_hmac('sha256', $transferCode, $brokerSecret);

        $response = Http::post(rtrim($brokerUrl, '/').'/oauth/claim', [
            'transfer_code' => $transferCode,
            'instance_id' => $instanceId,
            'signature' => $signature,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to claim tokens from broker: '.$response->body());
        }

        $data = $response->json();

        $expectedNonce = session()->pull("oauth_state.{$providerKey}");

        if (empty($expectedNonce) || ! hash_equals($expectedNonce, $data['nonce'] ?? '')) {
            throw new RuntimeException('OAuth state mismatch. Please try again.');
        }

        $accessToken = $data['access_token'] ?? null;
        if (empty($accessToken)) {
            throw new RuntimeException('No access token received from broker.');
        }

        $account->mergeSecret('oauth_token', $accessToken);
        $account->mergeSecret('auth_mode', 'authorization_code');

        if (! empty($data['refresh_token'])) {
            $account->mergeSecret('refresh_token', $data['refresh_token']);
        }

        if (! empty($data['expires_in'])) {
            $account->mergeSecret('token_expires_at', now()->addSeconds((int) $data['expires_in'])->toIso8601String());
        }

        if (! empty($data['refresh_token_expires_in'])) {
            $account->mergeSecret('refresh_token_expires_at', now()->addSeconds((int) $data['refresh_token_expires_in'])->toIso8601String());
        }

        $scopes = $data['extra']['scope'] ?? $data['scope'] ?? '';
        $account->mergeCredential('oauth_scopes', $scopes);
        $account->mergeCredential('oauth_connected_at', now()->toIso8601String());

        $account->save();

        if (method_exists($provider, 'afterConnectToAccount')) {
            $provider->afterConnectToAccount($accessToken, $account);
        }
    }

    /**
     * Refresh an expired token for a specific account via the broker.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int}
     */
    public function refreshTokenForAccount(string $providerKey, CarrierAccount $account): array
    {
        $refreshToken = $account->secret('refresh_token');

        if (empty($refreshToken)) {
            throw new RuntimeException("No refresh token stored for account {$account->id}. Please reconnect.");
        }

        $refreshExpiresAt = $account->secret('refresh_token_expires_at');
        if ($refreshExpiresAt && now()->greaterThan($refreshExpiresAt)) {
            throw new RuntimeException("Refresh token expired for account {$account->id}. Please reconnect.");
        }

        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        $signature = hash_hmac('sha256', $refreshToken, $brokerSecret);

        $response = Http::post(rtrim($brokerUrl, '/')."/oauth/{$providerKey}/refresh", [
            'refresh_token' => $refreshToken,
            'instance_id' => $instanceId,
            'signature' => $signature,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Token refresh failed: '.$response->body());
        }

        $data = $response->json();

        if (! empty($data['access_token'])) {
            $account->mergeSecret('oauth_token', $data['access_token']);
        }

        if (! empty($data['refresh_token'])) {
            $account->mergeSecret('refresh_token', $data['refresh_token']);
        }

        if (! empty($data['expires_in'])) {
            $account->mergeSecret('token_expires_at', now()->addSeconds((int) $data['expires_in'])->toIso8601String());
        }

        if (! empty($data['refresh_token_expires_in'])) {
            $account->mergeSecret('refresh_token_expires_at', now()->addSeconds((int) $data['refresh_token_expires_in'])->toIso8601String());
        }

        $account->save();

        return $data;
    }

    /**
     * Disconnect an account's OAuth connection, clearing its token fields.
     */
    public function disconnectAccount(CarrierAccount $account, string $providerKey): void
    {
        $provider = $this->registry->get($providerKey);

        $token = $account->secret('oauth_token');
        if ($token) {
            try {
                $provider->revokeToken($token);
            } catch (\Throwable) {
                // Best-effort; continue with local cleanup
            }
        }

        $secrets = $account->secret_credentials ?? [];
        foreach (['oauth_token', 'refresh_token', 'token_expires_at', 'refresh_token_expires_at', 'auth_mode'] as $key) {
            unset($secrets[$key]);
        }
        $account->secret_credentials = $secrets ?: null;

        $creds = $account->credentials ?? [];
        foreach (['oauth_scopes', 'oauth_connected_at'] as $key) {
            unset($creds[$key]);
        }
        $account->credentials = $creds ?: null;

        $account->save();
    }

    /**
     * Check if a specific account is connected via OAuth.
     */
    public function isAccountConnected(CarrierAccount $account): bool
    {
        return ! empty($account->secret('oauth_token'));
    }
}
