<?php

namespace App\Listeners;

use App\Events\PackageShipped;
use App\Services\ShipmentImport\PackageExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;

class ExportShippedPackage implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 600, 1200];

    public function handle(PackageShipped $event): void
    {
        $result = app(PackageExportService::class)->exportPackage($event->package);

        if ($result->shouldRetry()) {
            throw new RuntimeException(implode('; ', $result->retryableErrors));
        }
    }
}
