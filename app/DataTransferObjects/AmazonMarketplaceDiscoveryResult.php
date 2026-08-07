<?php

namespace App\DataTransferObjects;

final readonly class AmazonMarketplaceDiscoveryResult
{
    /**
     * @param  list<array{id: string, name: string, country_code: string, is_participating: bool, has_suspended_listings: bool}>  $marketplaces
     */
    public function __construct(
        public bool $succeeded,
        public array $marketplaces,
        public ?string $selectedMarketplaceId,
        public bool $selectionRequired,
        public bool $selectedMarketplaceUnavailable = false,
        public ?string $error = null,
    ) {}
}
