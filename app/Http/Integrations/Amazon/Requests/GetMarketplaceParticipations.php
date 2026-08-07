<?php

namespace App\Http\Integrations\Amazon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetMarketplaceParticipations extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/sellers/v1/marketplaceParticipations';
    }
}
