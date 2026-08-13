<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\ShipmentImport\ImportReferenceResolver;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class BackfillAmazonShippingMethods extends Command
{
    protected $signature = 'amazon:backfill-shipping-methods
        {--data-source= : Limit to a single Amazon data source ID}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Fill missing shipping methods on imported Amazon shipments using the carrier service recorded on their shipped packages';

    /** @var array<int, Client|null> */
    private array $clients = [];

    public function handle(ImportReferenceResolver $references): int
    {
        $sourceIds = DataSource::query()
            ->where('source_type', AmazonSource::class)
            ->when($this->option('data-source'), fn ($query) => $query->whereKey((int) $this->option('data-source')))
            ->pluck('id');

        if ($sourceIds->isEmpty()) {
            $this->components->error('No Amazon data sources found.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $mapped = [];
        $unmapped = [];
        $withoutService = 0;

        Shipment::query()
            ->whereIn('data_source_id', $sourceIds)
            ->whereNull('shipping_method_id')
            ->whereNotNull('metadata->amazon_packages')
            ->orderBy('id')
            ->chunkById(500, function (Collection $shipments) use ($references, $dryRun, &$mapped, &$unmapped, &$withoutService): void {
                foreach ($shipments as $shipment) {
                    $service = $this->shippingServiceFor($shipment);

                    if ($service === null) {
                        $withoutService++;

                        continue;
                    }

                    $shippingMethodId = $references->shippingMethodIdFor(
                        ['shipping_method_id' => $service],
                        $this->clientFor($shipment->client_id),
                    );

                    if ($shippingMethodId === null) {
                        $unmapped[$service] = ($unmapped[$service] ?? 0) + 1;
                    } else {
                        $mapped[$service] = ($mapped[$service] ?? 0) + 1;
                    }

                    if ($dryRun) {
                        continue;
                    }

                    // Mass update to bypass the model's address-normalizing save hook.
                    Shipment::query()->whereKey($shipment->id)->update(array_filter([
                        'shipping_method_reference' => $service,
                        'shipping_method_id' => $shippingMethodId,
                    ], fn (mixed $value): bool => $value !== null));
                }
            });

        $this->report($mapped, $unmapped, $withoutService, $dryRun);

        return self::SUCCESS;
    }

    /**
     * The carrier service Amazon recorded on the shipment's first shipped package.
     */
    private function shippingServiceFor(Shipment $shipment): ?string
    {
        foreach ($shipment->metadata['amazon_packages'] ?? [] as $package) {
            $service = is_array($package) ? ($package['shippingService'] ?? null) : null;

            if (is_string($service) && trim($service) !== '') {
                return trim($service);
            }
        }

        return null;
    }

    private function clientFor(?int $clientId): ?Client
    {
        if ($clientId === null) {
            return null;
        }

        return $this->clients[$clientId] ??= Client::find($clientId);
    }

    /**
     * @param  array<string, int>  $mapped
     * @param  array<string, int>  $unmapped
     */
    private function report(array $mapped, array $unmapped, int $withoutService, bool $dryRun): void
    {
        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $mappedTotal = array_sum($mapped);
        $unmappedTotal = array_sum($unmapped);

        if ($withoutService > 0) {
            $this->components->warn("{$withoutService} shipments had no shippingService on any package and were skipped.");
        }

        if ($mappedTotal === 0 && $unmappedTotal === 0) {
            $this->components->info($prefix.'No Amazon shipments needed a shipping method backfill.');

            return;
        }

        $rows = [];

        foreach ($mapped as $service => $count) {
            $rows[] = [$service, $count, 'mapped'];
        }

        foreach ($unmapped as $service => $count) {
            $rows[] = [$service, $count, 'no alias — reference recorded only'];
        }

        $this->table(['Amazon service', 'Shipments', 'Result'], $rows);
        $this->components->info($prefix."Set a shipping method on {$mappedTotal} shipments; {$unmappedTotal} recorded an unmapped reference.");

        if ($unmappedTotal > 0) {
            $this->components->warn('Map the remaining references under Shipments → Unmapped Shipping References, then re-run this command.');
        }
    }
}
