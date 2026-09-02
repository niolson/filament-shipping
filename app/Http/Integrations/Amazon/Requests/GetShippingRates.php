<?php

namespace App\Http\Integrations\Amazon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Amazon Shipping API v2 `getRates`.
 *
 * Returns a `requestToken` plus one `Rate` per eligible offer, each carrying its own
 * `rateId`, carrier and service identity, price and promise. Both the token and the
 * rate ID are required by `purchaseShipment` and cannot be reconstructed from the
 * carrier and service, so they are the offer's identity — see ADR-0002 decision 4.
 *
 * `ineligibleRates` names services that exist but did not apply, with reason codes.
 */
class GetShippingRates extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly array $payload = [],
        private readonly string $businessId = 'AmazonShipping_US',
    ) {}

    /**
     * Amazon defaults this header to `AmazonShipping_UK` when it is omitted, which is
     * wrong for every marketplace we serve. It selects the regional Amazon shipping
     * business, not the carrier set — Amazon's own on-Amazon example sends
     * `AmazonShipping_US` and still returns USPS alongside Amazon Shipping.
     *
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return ['x-amzn-shipping-business-id' => $this->businessId];
    }

    public function resolveEndpoint(): string
    {
        return '/shipping/v2/shipments/rates';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->payload;
    }
}
