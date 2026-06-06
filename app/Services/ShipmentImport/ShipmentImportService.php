<?php

namespace App\Services\ShipmentImport;

use App\Contracts\DataSourceInterface;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\AddressReferenceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShipmentImportService
{
    public function __construct(
        private readonly DataSourceInterface $source,
        private readonly ImportReferenceResolver $references,
        private readonly ShipmentRowPreparer $rowPreparer,
        private readonly ShipmentBatchWriter $batchWriter,
        private readonly ShipmentItemImporter $itemImporter,
        private readonly ImportRunRecorder $runRecorder,
        private readonly DataSource $importSource,
    ) {}

    /**
     * Build a service with an explicit driver instance and record.
     * Callers must supply a real DataSource record — no auto-creation.
     */
    public static function forSource(DataSourceInterface $source, DataSource $record): self
    {
        $references = app(ImportReferenceResolver::class);

        return new self(
            source: $source,
            references: $references,
            rowPreparer: new ShipmentRowPreparer(app(AddressReferenceService::class), $references),
            batchWriter: app(ShipmentBatchWriter::class),
            itemImporter: new ShipmentItemImporter($references),
            runRecorder: ImportRunRecorder::forRecord($record),
            importSource: $record,
        );
    }

    /**
     * Build a service directly from an DataSource DB record.
     */
    public static function forRecord(DataSource $record): self
    {
        $source = app(DataSourceFactory::class)->make($record);
        $references = app(ImportReferenceResolver::class);

        return new self(
            source: $source,
            references: $references,
            rowPreparer: new ShipmentRowPreparer(app(AddressReferenceService::class), $references),
            batchWriter: app(ShipmentBatchWriter::class),
            itemImporter: new ShipmentItemImporter($references),
            runRecorder: ImportRunRecorder::forRecord($record),
            importSource: $record,
        );
    }

    /**
     * Run the import process
     */
    public function import(): ImportResult
    {
        $startTime = microtime(true);

        try {
            $this->source->validateConfiguration();
        } catch (\Exception $e) {
            return $this->runRecorder->configurationFailed($e->getMessage(), microtime(true) - $startTime);
        }

        $this->references->warm($this->importSource->client);

        try {
            $shipments = $this->source->fetchShipments();
        } catch (\Exception $e) {
            return $this->runRecorder->configurationFailed($e->getMessage(), microtime(true) - $startTime);
        }

        $this->runRecorder->started($shipments->count());

        $batchSize = config('shipment-import.behavior.batch_size', 100);

        Shipment::withoutSyncingToSearch(function () use ($shipments, $batchSize): void {
            $shipments->chunk($batchSize)->each(function (Collection $batch): void {
                DB::transaction(function () use ($batch): void {
                    $this->importBatch($batch);
                });
            });
        });

        return $this->runRecorder->completed(microtime(true) - $startTime);
    }

    private function importBatch(Collection $batch): void
    {
        $preparedRows = [];
        $validDataBySourceRecord = [];
        $clientColumn = $this->importSource->settings['client_column'] ?? null;

        foreach ($batch as $data) {
            try {
                $clientOverride = null;
                if ($clientColumn && isset($data['_client_column_value'])) {
                    $clientOverride = $this->resolveClientByName((string) $data['_client_column_value']);
                }

                $prepared = $this->rowPreparer->prepare($data, $this->importSource, $clientOverride);

                if (! $prepared->isValid()) {
                    $this->runRecorder->addError("Validation errors for shipment {$data['shipment_reference']}: ".implode(', ', $prepared->errors));

                    continue;
                }

                $preparedRows[] = $prepared->attributes;
                $validDataBySourceRecord[$prepared->attributes['source_record_id']] = $data;
            } catch (\Exception $e) {
                $this->runRecorder->recordImportError($data, $e);
            }
        }

        if ($preparedRows === []) {
            return;
        }

        $writeResult = $this->batchWriter->write($preparedRows, $this->importSource);

        $this->runRecorder->addStats([
            'shipments_created' => $writeResult->shipmentsCreated,
            'shipments_updated' => $writeResult->shipmentsUpdated,
        ]);

        foreach ($validDataBySourceRecord as $sourceRecordId => $data) {
            $shipment = $writeResult->shipmentsBySourceRecord[$sourceRecordId] ?? null;

            if (! $shipment) {
                continue;
            }

            $this->runRecorder->addStats($this->itemImporter->import($shipment, $this->source, $this->importSource));
            $this->runRecorder->recordShipmentEvent($shipment, $writeResult->wasExisting($sourceRecordId));

            $this->markSourceRecordExported($sourceRecordId, $data);
        }
    }

    /**
     * Look up a Client by name for multi-client database imports.
     * Returns null (no client scoping) when the name doesn't match any record.
     */
    private function resolveClientByName(string $name): ?Client
    {
        return Client::whereRaw('LOWER(name) = ?', [strtolower(trim($name))])->first();
    }

    private function markSourceRecordExported(string $sourceRecordId, array $data): void
    {
        try {
            if ($this->source->markExported($sourceRecordId)) {
                $this->runRecorder->increment('shipments_exported');
            }
        } catch (\Exception $e) {
            $this->runRecorder->recordSourceExportFailure($sourceRecordId, $data, $e);
        }
    }
}
