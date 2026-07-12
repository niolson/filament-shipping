<?php

namespace App\Http\Integrations\Google;

use Illuminate\Support\Str;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;

/**
 * Routes Google Address Validation calls through polybag-connect so the
 * PolyBag-owned API key never lands in a tenant container, and so usage
 * can be metered per tenant_id (same pattern as FedexRegistrationProxyConnector).
 */
class GoogleAddressValidationProxyConnector extends Connector
{
    private const PROXY_PATH_MAP = [
        '/v1:validateAddress' => '/google/address-validate',
    ];

    public function resolveBaseUrl(): string
    {
        return rtrim(config('services.oauth.broker_url'), '/');
    }

    protected function defaultHeaders(): array
    {
        return ['Accept' => 'application/json'];
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        $googlePath = $pendingRequest->getRequest()->resolveEndpoint();
        $proxyPath = self::PROXY_PATH_MAP[$googlePath] ?? $googlePath;

        $pendingRequest->setUrl($this->resolveBaseUrl().$proxyPath);

        $instanceId = config('services.oauth.instance_id');
        $secret = config('services.oauth.broker_secret');
        $nonce = Str::random(40);
        $signature = hash_hmac('sha256', "{$googlePath}:{$instanceId}:{$nonce}", $secret);

        $body = $pendingRequest->body()->all();
        $body['instance_id'] = $instanceId;
        $body['nonce'] = $nonce;
        $body['signature'] = $signature;
        $pendingRequest->body()->set($body);
    }
}
