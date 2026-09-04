<?php

namespace App\Http\Integrations\Amazon\Requests;

use App\Enums\AmazonSpApiRegion;
use App\Http\Integrations\Amazon\DeclaresSandboxRegion;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class ConfirmShipment extends Request implements DeclaresSandboxRegion, HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $orderId,
        private readonly array $payload = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return '/orders/v0/orders/'.rawurlencode($this->orderId).'/shipmentConfirmation';
    }

    protected function defaultBody(): array
    {
        return $this->payload;
    }

    /**
     * North America, despite the import running against FE.
     *
     * The two sandbox paths are unrelated fixtures rather than two halves of one
     * order: {@see AmazonSource::exportPackage()}
     * discards the imported order in sandbox and posts Amazon's own confirmShipment
     * test case, whose pattern-matched values are a US order ID and marketplace
     * (`ATVPDKIKX0DER`). Sending that to the FE host does not reach the test case.
     *
     * Declared rather than left to the connector default because the FE import makes
     * the opposite conclusion an easy one to draw.
     */
    public function sandboxRegion(): AmazonSpApiRegion
    {
        return AmazonSpApiRegion::NorthAmerica;
    }
}
