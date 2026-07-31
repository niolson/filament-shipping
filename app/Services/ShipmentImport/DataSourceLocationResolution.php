<?php

namespace App\Services\ShipmentImport;

use App\Models\DataSourceLocation;
use App\Models\Location;

class DataSourceLocationResolution
{
    public function __construct(
        public readonly DataSourceLocation $sourceLocation,
        public readonly ?Location $location = null,
        public readonly bool $ignored = false,
        public readonly ?string $error = null,
    ) {}
}
