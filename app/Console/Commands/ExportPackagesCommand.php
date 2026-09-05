<?php

namespace App\Console\Commands;

use App\Contracts\ExportDestinationInterface;
use App\Models\DataSource;
use App\Models\Package;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\PackageExportService;
use Illuminate\Console\Command;

class ExportPackagesCommand extends Command
{
    protected $signature = 'packages:export
                            {--dry-run : Preview what would be exported without making changes}
                            {--validate-only : Only validate the export destination configurations}
                            {--retry-permanent : Reopen permanent export failures before exporting}
                            {--scheduled : Apply the scheduled-run safety cutoff to unclaimed packages}
                            {--package= : Limit export and recovery to one package ID}';

    protected $description = 'Export shipped packages to configured external destinations';

    public function handle(PackageExportService $service): int
    {
        $packageOption = $this->option('package');

        if ($packageOption !== null && filter_var($packageOption, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('Package must be a positive integer.');

            return Command::FAILURE;
        }

        $packageId = $packageOption !== null ? (int) $packageOption : null;

        if ($this->option('validate-only')) {
            return $this->validateDestinations();
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($service, $packageId);
        }

        $this->info('Exporting shipped packages...');

        if ($this->option('retry-permanent')) {
            $reset = $service->retryPermanentFailures($packageId);
            $this->info("Reopened {$reset} permanent export failure(s).");
        }

        $results = $service->exportUnexported($packageId, scheduled: (bool) $this->option('scheduled'));

        if (empty($results)) {
            $this->info('No unexported packages found.');

            return Command::SUCCESS;
        }

        $succeeded = collect($results)->filter(fn ($r) => $r->success)->count();
        $failed = collect($results)->filter(fn ($r) => $r->hasErrors())->count();
        $permanentlyFailed = collect($results)
            ->filter(fn ($r) => $r->hasPermanentErrors())
            ->count();
        $deferred = collect($results)->filter(fn ($r) => $r->deferred)->count();

        $this->newLine();
        $this->info('Export completed!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Packages Processed', count($results)],
                ['Succeeded', $succeeded],
                ['Failed', $failed],
                ['Deferred', $deferred],
            ]
        );

        if ($failed > 0) {
            $this->newLine();
            $this->warn('Errors encountered:');
            foreach ($results as $packageId => $result) {
                foreach ($result->errors as $error) {
                    $this->error("  Package #{$packageId}: {$error}");
                }
            }
        }

        $shouldFail = (bool) $this->option('scheduled')
            ? $permanentlyFailed > 0
            : $failed > 0;

        return $shouldFail ? Command::FAILURE : Command::SUCCESS;
    }

    private function validateDestinations(): int
    {
        $this->info('Validating export destination configurations...');

        $sources = DataSource::where('active', true)
            ->whereJsonContains('settings->export_enabled', true)
            ->get();

        if ($sources->isEmpty()) {
            $this->warn('No export-enabled data sources configured.');

            return Command::SUCCESS;
        }

        $hasErrors = false;
        $factory = app(DataSourceFactory::class);

        foreach ($sources as $importSource) {
            try {
                $driver = $factory->make($importSource);

                if (! $driver instanceof ExportDestinationInterface) {
                    $this->error("  {$importSource->name}: driver does not support export.");
                    $hasErrors = true;

                    continue;
                }

                $driver->validateExportConfiguration();
                $this->info("  {$importSource->name}: OK");
            } catch (\Exception $e) {
                $this->error("  {$importSource->name}: {$e->getMessage()}");
                $hasErrors = true;
            }
        }

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }

    private function dryRun(PackageExportService $service, ?int $packageId): int
    {
        $this->info('Running in dry-run mode (no changes will be made)...');

        $retryPermanentFailures = (bool) $this->option('retry-permanent');

        if ($retryPermanentFailures) {
            $count = $service->countRecoverablePermanentFailures($packageId);
            $this->info("Would reopen {$count} permanent export failure(s).");
        }

        $packages = $service->previewUnexported($packageId, $retryPermanentFailures);

        if ($packages->isEmpty()) {
            $this->info('No unexported packages found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$packages->count()} packages to export:");
        $this->newLine();

        $rows = $packages->map(fn (Package $p): array => [
            $p->id,
            $p->tracking_number ?? 'N/A',
            $p->carrier ?? 'N/A',
            $p->shipment?->shipment_reference ?? 'N/A',
            $p->shipment?->channel?->name ?? 'No Channel',
        ])->toArray();

        $this->table(
            ['Package ID', 'Tracking', 'Carrier', 'Shipment Ref', 'Channel'],
            $rows
        );

        return Command::SUCCESS;
    }
}
