<?php

use App\Contracts\ExportDestinationInterface;
use App\Contracts\ImportSourceInterface;
use App\Models\Channel;
use App\Models\ImportSource;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\ShipmentImport\PackageExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

function fakeExportSource(bool $exportEnabled = true, ?string $exportError = null): string
{
    $class = new class([]) implements ExportDestinationInterface, ImportSourceInterface
    {
        public static bool $staticExportEnabled = true;

        public static ?string $staticExportError = null;

        /** @var array<int, array<string, mixed>> */
        public static array $exportedData = [];

        public function __construct(array $config = []) // @phpstan-ignore constructor.unusedParameter
        {}

        public function fetchShipments(): Collection
        {
            return collect();
        }

        public function fetchShipmentItems(string $sourceRecordId): Collection
        {
            return collect();
        }

        public function validateConfiguration(): void {}

        public function getFieldMapping(): array
        {
            return [];
        }

        public function markExported(string $sourceRecordId): bool
        {
            return false;
        }

        public function getDestinationName(): string
        {
            return 'test';
        }

        public function exportPackage(array $data): void
        {
            if (self::$staticExportError) {
                throw new RuntimeException(self::$staticExportError);
            }
            self::$exportedData[] = $data;
        }

        public function validateExportConfiguration(): void
        {
            if (! self::$staticExportEnabled) {
                throw new InvalidArgumentException('Export is not enabled.');
            }
        }
    };

    $className = get_class($class);
    $className::$staticExportEnabled = $exportEnabled;
    $className::$staticExportError = $exportError;
    $className::$exportedData = [];

    return $className;
}

/**
 * @param  array<string, string>  $fieldMapping
 */
function fakeImportSource(string $driverClass, array $fieldMapping = [], bool $exportEnabled = true): ImportSource
{
    return ImportSource::factory()->create([
        'driver' => $driverClass,
        'settings' => [
            'export_enabled' => $exportEnabled,
            'export_field_mapping' => $fieldMapping,
        ],
    ]);
}

function createShippedPackage(?Channel $channel = null, ?ImportSource $importSource = null): Package
{
    $shipment = Shipment::factory()->create([
        'channel_id' => $channel?->id,
        'import_source_id' => $importSource?->id,
        'shipment_reference' => 'REF-001',
    ]);

    return Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => '1234567890',
        'weight' => 5.50,
        'height' => 10.00,
        'width' => 8.00,
        'length' => 12.00,
        'cost' => 7.99,
        'carrier' => 'USPS',
        'service' => 'Priority Mail',
    ]);
}

it('exports package data using configured field mapping', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $importSource = fakeImportSource($driverClass, [
        'tracking_number' => 'tracking',
        'shipment_reference' => 'ref',
        'weight' => 'weight',
    ]);
    $package = createShippedPackage($channel, $importSource);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(1);
    expect($result->destinationsSucceeded)->toBe(1);
    expect($driverClass::$exportedData)->toHaveCount(1);
    expect($driverClass::$exportedData[0])->toBe([
        'tracking' => '1234567890',
        'ref' => 'REF-001',
        'weight' => '5.50',
    ]);
});

it('skips export when shipment has no import source', function (): void {
    $channel = Channel::factory()->create(['name' => 'UnlinkedChannel']);
    $package = createShippedPackage($channel);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(0);
    expect($result->destinationsSucceeded)->toBe(0);
});

it('skips export when import source has export_enabled false', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $importSource = fakeImportSource($driverClass, exportEnabled: false);
    $package = createShippedPackage($channel, $importSource);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(0);
});

it('marks package as exported on success', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $importSource = fakeImportSource($driverClass, ['tracking_number' => 'tracking', 'shipment_reference' => 'ref']);
    $package = createShippedPackage($channel, $importSource);

    $service = new PackageExportService;
    $service->exportPackage($package);

    expect($package->fresh()->exported)->toBeTrue();
});

it('does not mark package as exported when a destination fails', function (): void {
    $driverClass = fakeExportSource(exportError: 'Connection refused');
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $importSource = fakeImportSource($driverClass, ['tracking_number' => 'tracking', 'shipment_reference' => 'ref']);
    $package = createShippedPackage($channel, $importSource);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeFalse();
    expect($result->hasErrors())->toBeTrue();
    expect($result->errors[0])->toContain('Connection refused');
    expect($package->fresh()->exported)->toBeFalse();
});

it('skips export when driver does not implement ExportDestinationInterface', function (): void {
    $importOnlyClass = new class([]) implements ImportSourceInterface
    {
        public function __construct(array $config = []) {}

        public function fetchShipments(): Collection
        {
            return collect();
        }

        public function fetchShipmentItems(string $sourceRecordId): Collection
        {
            return collect();
        }

        public function validateConfiguration(): void {}

        public function getFieldMapping(): array
        {
            return [];
        }

        public function markExported(string $sourceRecordId): bool
        {
            return false;
        }
    };

    $driverClass = get_class($importOnlyClass);
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $importSource = ImportSource::factory()->create([
        'driver' => $driverClass,
        'settings' => ['export_enabled' => true],
    ]);
    $package = createShippedPackage($channel, $importSource);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(0);
});

it('exports unexported packages via exportUnexported', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $importSource = fakeImportSource($driverClass, ['tracking_number' => 'tracking', 'shipment_reference' => 'ref']);

    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'import_source_id' => $importSource->id,
        'shipment_reference' => 'REF-A',
    ]);
    $pkg1 = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'exported' => false,
        'tracking_number' => 'TRACK-A',
    ]);

    $shipment2 = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'import_source_id' => $importSource->id,
        'shipment_reference' => 'REF-B',
    ]);
    $pkg2 = Package::factory()->shipped()->create([
        'shipment_id' => $shipment2->id,
        'exported' => false,
        'tracking_number' => 'TRACK-B',
    ]);

    // Already exported — should be skipped
    $shipment3 = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'import_source_id' => $importSource->id,
        'shipment_reference' => 'REF-C',
    ]);
    Package::factory()->exported()->create([
        'shipment_id' => $shipment3->id,
        'tracking_number' => 'TRACK-C',
    ]);

    $service = new PackageExportService;
    $results = $service->exportUnexported();

    expect($results)->toHaveCount(2);
    expect($pkg1->fresh()->exported)->toBeTrue();
    expect($pkg2->fresh()->exported)->toBeTrue();
});

it('runs export command with dry-run option', function (): void {
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $shipment = Shipment::factory()->create(['channel_id' => $channel->id, 'shipment_reference' => 'REF-CMD']);
    Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'exported' => false,
        'tracking_number' => 'TRACK-CMD',
        'carrier' => 'USPS',
    ]);

    $this->artisan('packages:export', ['--dry-run' => true])
        ->expectsOutputToContain('1 packages to export')
        ->expectsOutputToContain('TRACK-CMD')
        ->assertExitCode(0);
});

it('runs export command with validate-only when no export-enabled sources', function (): void {
    $this->artisan('packages:export', ['--validate-only' => true])
        ->expectsOutputToContain('No export-enabled import sources configured')
        ->assertExitCode(0);
});
