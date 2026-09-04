<?php

namespace App\Enums;

use App\Http\Integrations\Amazon\DeclaresSandboxRegion;

/**
 * An SP-API region, which is a host selector rather than a marketplace.
 *
 * Production is always North America for the marketplaces we serve, so this exists
 * for the sandbox, where Amazon scopes each API's test cases to a region and the two
 * APIs we use disagree about which one they need. See {@see DeclaresSandboxRegion}.
 */
enum AmazonSpApiRegion: string
{
    case NorthAmerica = 'na';
    case Europe = 'eu';
    case FarEast = 'fe';

    public function sandboxUrl(): string
    {
        return "https://sandbox.sellingpartnerapi-{$this->value}.amazon.com";
    }
}
