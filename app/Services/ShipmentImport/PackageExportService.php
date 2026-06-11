<?php

namespace App\Services\ShipmentImport;

use App\Contracts\ExportDestinationInterface;
use App\Enums\PackageStatus;
use App\Models\DataSource;
use App\Models\Package;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

class PackageExportService
{
    /**
     * Export a shipped package's data to all configured destinations:
     *  1. The client's explicit export override, or the shipment's originating data source.
     *  2. Every active data source with global_export enabled (fan-out; deduped against #1).
     *     Global fan-out is a multi-client feature; in single-client mode the toggle is
     *     hidden in the UI and exports are strictly source-scoped.
     */
    public function exportPackage(Package $package): ExportResult
    {
        $package->loadMissing('shipment.dataSource');
        $shipment = $package->shipment;

        $primary = $shipment?->dataSource;

        $multiClient = (bool) app(SettingsService::class)->get('multi_client_enabled', false);

        $globalSources = ! $multiClient ? collect() : DataSource::where('global_export', true)
            ->where('active', true)
            ->whereJsonContains('settings->export_enabled', true)
            ->get()
            ->reject(fn (DataSource $s) => $primary && $s->id === $primary->id);

        $destinations = collect();

        if ($primary && ($primary->settings['export_enabled'] ?? false)) {
            $destinations->push($primary);
        }

        $destinations = $destinations->merge($globalSources);

        if ($destinations->isEmpty()) {
            return new ExportResult(success: true);
        }

        $attempted = 0;
        $succeeded = 0;
        $errors = [];

        foreach ($destinations as $source) {
            $driver = app(DataSourceFactory::class)->make($source);

            if (! $driver instanceof ExportDestinationInterface) {
                continue;
            }

            $fieldMapping = $source->settings['export_field_mapping'] ?? [
                'tracking_number' => 'tracking_number',
                'carrier' => 'carrier',
                'service' => 'service',
                'weight' => 'weight',
                'shipment_reference' => 'shipment_reference',
                'fulfillment_order_id' => 'fulfillment_order_id',
                'amazon_order_id' => 'amazon_order_id',
            ];

            $data = $this->buildExportData($package, $fieldMapping);
            $attempted++;

            try {
                $driver->exportPackage($data);
                $succeeded++;
            } catch (\Exception $e) {
                $this->log('error', "Export to {$source->name} failed", [
                    'package_id' => $package->id,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = "{$source->name}: {$e->getMessage()}";
            }
        }

        $success = empty($errors);

        if ($success) {
            $package->update(['exported' => true]);
        }

        return new ExportResult(
            success: $success,
            destinationsAttempted: $attempted,
            destinationsSucceeded: $succeeded,
            errors: $errors,
        );
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
            ->with('shipment.dataSource')
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
