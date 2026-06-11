<?php

namespace App\Services\ShipmentImport;

use App\Models\Shipment;
use Illuminate\Support\Collection;

class ShipmentBatchWriteResult
{
    /**
     * @param  Collection<string, Shipment>  $shipmentsBySourceRecord
     * @param  array<int, string>  $sourceRecordIds
     * @param  array<int, string>  $existingSourceRecordIds  Existing shipments that were rewritten
     * @param  array<int, string>  $skippedSourceRecordIds  Existing shipments left untouched
     */
    public function __construct(
        public readonly Collection $shipmentsBySourceRecord,
        public readonly array $sourceRecordIds,
        public readonly array $existingSourceRecordIds,
        public readonly array $skippedSourceRecordIds,
        public readonly int $shipmentsCreated,
        public readonly int $shipmentsUpdated,
        public readonly int $shipmentsSkipped,
    ) {}

    public function wasExisting(string $sourceRecordId): bool
    {
        return in_array($sourceRecordId, $this->existingSourceRecordIds, true);
    }

    public function wasSkipped(string $sourceRecordId): bool
    {
        return in_array($sourceRecordId, $this->skippedSourceRecordIds, true);
    }
}
