<?php

namespace App\Services\ShipmentImport;

use App\Enums\ImportExistingBehavior;
use App\Enums\ShipmentStatus;
use App\Models\DataSource;
use App\Models\Shipment;
use Illuminate\Support\Collection;

class ShipmentBatchWriter
{
    /**
     * Write prepared shipment rows, honoring the source's behavior for rows
     * that already exist locally:
     *
     *  - Shipped/void shipments are never updated — they are historical records.
     *  - `update` always rewrites open shipments from the source (legacy behavior).
     *  - `skip` never touches existing shipments.
     *  - `update_if_changed` rewrites only when the row's source_checksum differs
     *    from the one stored at the previous import.
     *
     * @param  array<int, array<string, mixed>>  $preparedRows
     */
    public function write(array $preparedRows, DataSource $importSource): ShipmentBatchWriteResult
    {
        if ($preparedRows === []) {
            return new ShipmentBatchWriteResult(collect(), [], [], [], 0, 0, 0);
        }

        $behavior = ImportExistingBehavior::tryFrom((string) ($importSource->settings['on_existing'] ?? ''))
            ?? ImportExistingBehavior::default();

        $now = now();

        foreach ($preparedRows as &$preparedRow) {
            $preparedRow['client_id'] ??= $importSource->client_id;
            $preparedRow['created_at'] = $now;
            $preparedRow['updated_at'] = $now;
        }
        unset($preparedRow);

        $sourceRecordIds = array_column($preparedRows, 'source_record_id');

        /** @var Collection<string, Shipment> $existingShipments */
        $existingShipments = Shipment::where('data_source_id', $importSource->id)
            ->whereIn('source_record_id', $sourceRecordIds)
            ->get(['id', 'source_record_id', 'status', 'source_checksum'])
            ->keyBy('source_record_id');

        $rowsToWrite = [];
        $updatedSourceRecordIds = [];
        $skippedSourceRecordIds = [];

        foreach ($preparedRows as $row) {
            $existing = $existingShipments->get($row['source_record_id']);

            if (! $existing) {
                $rowsToWrite[] = $row;

                continue;
            }

            if ($this->shouldUpdate($existing, $row, $behavior)) {
                $rowsToWrite[] = $row;
                $updatedSourceRecordIds[] = $row['source_record_id'];
            } else {
                $skippedSourceRecordIds[] = $row['source_record_id'];
            }
        }

        $updateColumns = [
            'client_id',
            'shipment_reference', 'source_checksum',
            'first_name', 'last_name', 'company',
            'address1', 'address2', 'city', 'state_or_province', 'postal_code', 'country',
            'phone', 'phone_e164', 'phone_extension', 'email', 'value',
            'validation_message', 'shipping_method_reference', 'shipping_method_id',
            'channel_reference', 'deliver_by', 'metadata', 'updated_at',
            'channel_id',
        ];

        if ($rowsToWrite !== []) {
            Shipment::upsert($rowsToWrite, ['data_source_id', 'source_record_id'], $updateColumns);
        }

        $shipmentsBySourceRecord = Shipment::where('data_source_id', $importSource->id)
            ->whereIn('source_record_id', $sourceRecordIds)
            ->get()
            ->keyBy('source_record_id');

        return new ShipmentBatchWriteResult(
            shipmentsBySourceRecord: $shipmentsBySourceRecord,
            sourceRecordIds: $sourceRecordIds,
            existingSourceRecordIds: $updatedSourceRecordIds,
            skippedSourceRecordIds: $skippedSourceRecordIds,
            shipmentsCreated: count($rowsToWrite) - count($updatedSourceRecordIds),
            shipmentsUpdated: count($updatedSourceRecordIds),
            shipmentsSkipped: count($skippedSourceRecordIds),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function shouldUpdate(Shipment $existing, array $row, ImportExistingBehavior $behavior): bool
    {
        if ($existing->status !== ShipmentStatus::Open) {
            return false;
        }

        return match ($behavior) {
            ImportExistingBehavior::Update => true,
            ImportExistingBehavior::Skip => false,
            ImportExistingBehavior::UpdateIfChanged => $existing->source_checksum !== ($row['source_checksum'] ?? null),
        };
    }
}
