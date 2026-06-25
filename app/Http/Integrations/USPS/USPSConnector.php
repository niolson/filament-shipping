<?php

namespace App\Http\Integrations\USPS;

use App\Http\Integrations\Concerns\HasCachedAuthentication;
use App\Http\Integrations\Concerns\RetriesTransientErrors;
use App\Http\Integrations\USPS\Requests\PaymentAuthorization;
use App\Models\CarrierAccount;
use App\Services\OAuthService;
use App\Services\SettingsService;
use Carbon\Carbon;
use DateInterval;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Saloon\Contracts\OAuthAuthenticator;
use Saloon\Helpers\OAuth2\OAuthConfig;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\OAuth2\ClientCredentialsGrant;
use Saloon\Traits\Plugins\HasTimeout;

class USPSConnector extends Connector
{
    use ClientCredentialsGrant {
        getAccessToken as protected saloonGetAccessToken;
    }
    use HasCachedAuthentication;
    use HasTimeout;
    use RetriesTransientErrors;

    protected int $connectTimeout = 5;

    private ?CarrierAccount $carrierAccount = null;

    public static function forAccount(CarrierAccount $account): self
    {
        $instance = new self;
        $instance->carrierAccount = $account;

        return $instance;
    }

    public function getRequestTimeout(): float
    {
        return (float) app(SettingsService::class)->get('carrier_api_timeout', 15);
    }

    /**
     * Number of retry attempts for failed requests.
     */
    public ?int $tries = 3;

    /**
     * Interval in milliseconds between retry attempts.
     */
    public ?int $retryInterval = 500;

    /**
     * Use exponential backoff for retries.
     */
    public ?bool $useExponentialBackoff = true;

    public function resolveBaseUrl(): string
    {
        if (app(SettingsService::class)->get('sandbox_mode', false)) {
            return config('services.usps.sandbox_url', 'https://apis-tem.usps.com');
        }

        return config('services.usps.base_url', 'https://apis.usps.com');
    }

    protected function defaultOauthConfig(): OAuthConfig
    {
        $clientId = $this->carrierAccount?->secret('client_id') ?? '';
        $clientSecret = $this->carrierAccount?->secret('client_secret') ?? '';

        return OAuthConfig::make()
            ->setClientId((string) $clientId)
            ->setClientSecret((string) $clientSecret)
            ->setDefaultScopes(['addresses', 'domestic-prices', 'international-prices', 'payments', 'labels', 'international-labels', 'shipments', 'scan-forms', 'tracking'])
            ->setTokenEndpoint('/oauth2/v3/token');
    }

    /**
     * @param  array<string>  $scopes
     * @param  callable(Request): (void)|null  $requestModifier
     * @return ($returnResponse is true ? Response : OAuthAuthenticator)
     */
    public function getAccessToken(array $scopes = [], string $scopeSeparator = ' ', bool $returnResponse = false, ?callable $requestModifier = null): OAuthAuthenticator|Response
    {
        if ($returnResponse) {
            return $this->saloonGetAccessToken($scopes, $scopeSeparator, true, $requestModifier);
        }

        $requestedScopes = $scopes === [] ? $this->oauthConfig()->getDefaultScopes() : $scopes;
        $response = $this->saloonGetAccessToken($scopes, $scopeSeparator, true, $requestModifier);
        $responseData = $this->decodeTokenResponseSafely($response);

        Log::channel('usps-validation')->info('TOKEN RESPONSE', [
            'uri' => rtrim($this->resolveBaseUrl(), '/').'/oauth2/v3/token',
            'status' => $response->status(),
            'requested_scopes' => $requestedScopes,
            'granted_scope' => $responseData['scope'] ?? $responseData['scopes'] ?? null,
            'expires_in' => $responseData['expires_in'] ?? null,
            'token_type' => $responseData['token_type'] ?? null,
        ]);

        $response->throw();

        return $this->createOAuthAuthenticatorFromResponse($response);
    }

    protected static function getAuthenticatorCacheKey(): string
    {
        return 'usps_authenticator';
    }

    /**
     * Get an authenticated connector, using OAuth token if connected via auth code flow,
     * otherwise falling back to client credentials.
     */
    public static function getAuthenticatedConnector(?CarrierAccount $account = null): static
    {
        $settings = app(SettingsService::class);

        $authMode = $account?->secret('auth_mode');

        if ($authMode === 'authorization_code') {
            if ($settings->get('sandbox_mode', false)) {
                throw new RuntimeException('USPS is not available in sandbox mode when connected via OAuth. Disable sandbox mode to use your USPS account.');
            }

            return static::fromOAuthToken($account);
        }

        /** @phpstan-ignore new.static */
        $connector = $account ? static::forAccount($account) : new static;
        $cacheKey = $account?->secret('client_id')
            ? "usps_authenticator:{$account->id}"
            : static::getAuthenticatorCacheKey();

        $cached = Cache::get($cacheKey);

        if (! is_array($cached)) {
            $cached = Cache::lock($cacheKey.':lock', 10)->block(5, function () use ($connector, $cacheKey) {
                $recached = Cache::get($cacheKey);
                if (is_array($recached)) {
                    return $recached;
                }

                $authenticator = $connector->getAccessToken();
                $data = static::serializeAuthenticator($authenticator);

                Cache::put(
                    $cacheKey,
                    $data,
                    $authenticator->getExpiresAt()->sub(DateInterval::createFromDateString('10 minutes'))
                );

                return $data;
            });
        }

        $connector->authenticate(static::deserializeAuthenticator($cached));

        return $connector;
    }

    /**
     * OAuth authorization code flow — uses stored token with automatic refresh.
     */
    private static function fromOAuthToken(?CarrierAccount $account = null): static
    {
        /** @phpstan-ignore new.static */
        $connector = $account ? static::forAccount($account) : new static;
        $cacheKey = $account ? "usps_oauth_token:{$account->id}" : 'usps_oauth_token';

        $cachedToken = Cache::get($cacheKey);

        if ($cachedToken) {
            $connector->authenticate(new TokenAuthenticator($cachedToken));

            return $connector;
        }

        $token = Cache::lock($cacheKey.':lock', 10)->block(5, function () use ($cacheKey, $account) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return $cached;
            }

            $accessToken = $account?->secret('oauth_token');
            $expiresAt = $account?->secret('token_expires_at');

            if (! $accessToken) {
                $context = $account ? "account {$account->id}" : 'the carrier account';
                throw new RuntimeException("USPS OAuth token not found. Please reconnect via {$context}.");
            }

            if ($expiresAt && Carbon::parse($expiresAt)->subMinutes(10)->isFuture()) {
                $ttl = Carbon::parse($expiresAt)->subMinutes(10)->diffInSeconds(now());
                Cache::put($cacheKey, $accessToken, (int) $ttl);

                return $accessToken;
            }

            return static::refreshOAuthToken($cacheKey, $account);
        });

        $connector->authenticate(new TokenAuthenticator($token));

        return $connector;
    }

    /**
     * Refresh the OAuth access token via the broker.
     */
    private static function refreshOAuthToken(string $cacheKey, ?CarrierAccount $account = null): string
    {
        $data = $account
            ? app(OAuthService::class)->refreshTokenForAccount('usps', $account)
            : app(OAuthService::class)->refreshToken('usps');

        $newAccessToken = $data['access_token'] ?? null;

        if (! $newAccessToken) {
            throw new RuntimeException('USPS token refresh response missing access_token.');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 14400);
        $cacheTtl = max($expiresIn - 600, 60);
        Cache::put($cacheKey, $newAccessToken, $cacheTtl);

        return $newAccessToken;
    }

    /**
     * @deprecated Use getAuthenticatedConnector() instead
     */
    public static function getUspsConnector(): self
    {
        return self::getAuthenticatedConnector();
    }

    /**
     * Fetches a USPS payment authorization token for the given account.
     * Keyed by account ID so invalidation is a single Cache::forget on the account.
     * Pass null to fall back to global Settings credentials.
     */
    public static function getUspsPaymentAuthorizationToken(?int $accountId = null): string
    {
        $cacheKey = 'usps_payment_authorization_token:'.($accountId ?? 'global');

        return Cache::get($cacheKey, function () use ($cacheKey, $accountId) {
            $account = $accountId ? CarrierAccount::find($accountId) : null;

            $crid = $account?->credential('crid');
            $mid = $account?->credential('mid');
            // EPS account number is distinct from CRID; auto-populated from OAuth JWT
            $epsAccount = $account?->credential('eps_account') ?? $crid;

            $request = new PaymentAuthorization;
            $request->body()->set([
                'roles' => [
                    [
                        'roleName' => 'PAYER',
                        'CRID' => $crid,
                        'MID' => $mid,
                        'manifestMID' => $mid,
                        'accountType' => 'EPS',
                        'accountNumber' => $epsAccount,
                    ],
                    [
                        'roleName' => 'LABEL_OWNER',
                        'CRID' => $crid,
                        'MID' => $mid,
                        'manifestMID' => $mid,
                    ],
                ],
            ]);

            $connector = self::getAuthenticatedConnector($account);
            $response = $connector->send($request);
            $paymentAuthorizationToken = $response->json('paymentAuthorizationToken');

            if (empty($paymentAuthorizationToken)) {
                logger()->error('USPS payment authorization failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'account_id' => $accountId,
                ]);
                throw new RuntimeException('USPS payment authorization returned empty token');
            }

            Cache::put($cacheKey, $paymentAuthorizationToken, now()->addHours(7));

            return $paymentAuthorizationToken;
        });
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decodeTokenResponseSafely(Response $response): array
    {
        try {
            $decoded = $response->json();

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return ['body' => $response->body()];
        }
    }
}
