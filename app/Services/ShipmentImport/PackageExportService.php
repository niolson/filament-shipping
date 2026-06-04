<?php

namespace App\Services\ShipmentImport;

use App\Contracts\ExportDestinationInterface;
use App\Enums\PackageStatus;
use App\Models\Package;
use Illuminate\Support\Facades\Log;

class PackageExportService
{
    /**
     * Export a shipped package's data to the import source that created its shipment.
     */
    public function exportPackage(Package $package): ExportResult
    {
        $package->loadMissing('shipment.client.exportSource', 'shipment.importSource');
        $shipment = $package->shipment;

        // Client-level override takes priority; fall back to the originating import source.
        $importSource = $shipment?->client?->exportSource ?? $shipment?->importSource;

        if (! $importSource || ! ($importSource->settings['export_enabled'] ?? false)) {
            return new ExportResult(success: true);
        }

        $driver = app(ImportSourceFactory::class)->make($importSource);

        if (! $driver instanceof ExportDestinationInterface) {
            return new ExportResult(success: true);
        }

        $fieldMapping = $importSource->settings['export_field_mapping'] ?? [
            'tracking_number' => 'tracking_number',
            'carrier' => 'carrier',
            'service' => 'service',
            'weight' => 'weight',
            'shipment_reference' => 'shipment_reference',
            'fulfillment_order_id' => 'fulfillment_order_id',
            'amazon_order_id' => 'amazon_order_id',
        ];

        $data = $this->buildExportData($package, $fieldMapping);

        try {
            $driver->exportPackage($data);
            $package->update(['exported' => true]);

            return new ExportResult(
                success: true,
                destinationsAttempted: 1,
                destinationsSucceeded: 1,
            );
        } catch (\Exception $e) {
            $this->log('error', "Export to {$importSource->name} failed", [
                'package_id' => $package->id,
                'error' => $e->getMessage(),
            ]);

            return new ExportResult(
                success: false,
                destinationsAttempted: 1,
                destinationsSucceeded: 0,
                errors: ["{$importSource->name}: {$e->getMessage()}"],
            );
        }
    }

    /**
     * Export all shipped but unexported packages.
     *
     * @return array<int, ExportResult> Keyed by package ID
     */
    public function exportUnexported(): array
    {
        $packages = Package::where('status', PackageStatus::Shipped)
            ->where('exported', false)
            ->with('shipment.client.exportSource', 'shipment.importSource')
            ->get();

        $results = [];

        foreach ($packages as $package) {
            $results[$package->id] = $this->exportPackage($package);
        }

        return $results;
    }

    /**
     * Build the export data array from a package using the configured field mapping.
     *
     * @return array<string, mixed>
     */
    private function buildExportData(Package $package, array $fieldMapping): array
    {
        $available = [
            'tracking_number' => $package->tracking_number,
            'weight' => $package->weight,
            'height' => $package->height,
            'width' => $package->width,
            'length' => $package->length,
            'cost' => $package->cost,
            'carrier' => $package->carrier,
            'service' => $package->service,
            'shipment_reference' => $package->shipment?->shipment_reference,
            'fulfillment_order_id' => $package->shipment?->metadata['shopify_fulfillment_order_ids'][0] ?? null,
            'amazon_order_id' => $package->shipment?->metadata['amazon_order_id'] ?? null,
        ];

        $mapped = [];

        foreach ($fieldMapping as $internalName => $parameterName) {
            if (array_key_exists($internalName, $available)) {
                $mapped[$parameterName] = $available[$internalName];
            }
        }

        return $mapped;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $channel = config('shipment-import.logging.channel', 'stack');
        Log::channel($channel)->log($level, $message, $context);
    }
}
