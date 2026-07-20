<?php

use App\Contracts\DataSourceInterface;
use App\Contracts\ExportDestinationInterface;
use App\Models\Channel;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\SettingsService;
use App\Services\ShipmentImport\PackageExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

function fakeExportSource(bool $exportEnabled = true, ?string $exportError = null): string
{
    $class = new class([]) implements DataSourceInterface, ExportDestinationInterface
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
function fakeDataSource(string $driverClass, array $fieldMapping = [], bool $exportEnabled = true): DataSource
{
    return DataSource::factory()->create([
        'source_type' => $driverClass,
        'settings' => [
            'export_enabled' => $exportEnabled,
            'export_field_mapping' => $fieldMapping,
        ],
    ]);
}

function createShippedPackage(?Channel $channel = null, ?DataSource $importSource = null): Package
{
    $shipment = Shipment::factory()->create([
        'channel_id' => $channel?->id,
        'data_source_id' => $importSource?->id,
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
    $importSource = fakeDataSource($driverClass, [
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
    $importSource = fakeDataSource($driverClass, exportEnabled: false);
    $package = createShippedPackage($channel, $importSource);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(0);
});

it('marks package as exported on success', function (): void {
    $driverClass = fakeExportSource();
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $importSource = fakeDataSource($driverClass, ['tracking_number' => 'tracking', 'shipment_reference' => 'ref']);
    $package = createShippedPackage($channel, $importSource);

    $service = new PackageExportService;
    $service->exportPackage($package);

    expect($package->fresh()->exported)->toBeTrue();
});

it('does not mark package as exported when a destination fails', function (): void {
    $driverClass = fakeExportSource(exportError: 'Connection refused');
    $channel = Channel::factory()->create(['name' => 'TestChannel']);
    $importSource = fakeDataSource($driverClass, ['tracking_number' => 'tracking', 'shipment_reference' => 'ref']);
    $package = createShippedPackage($channel, $importSource);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeFalse();
    expect($result->hasErrors())->toBeTrue();
    expect($result->errors[0])->toContain('Connection refused');
    expect($package->fresh()->exported)->toBeFalse();
});

it('skips export when driver does not implement ExportDestinationInterface', function (): void {
    $importOnlyClass = new class([]) implements DataSourceInterface
    {
        public function __construct(array $config = []) {} // @phpstan-ignore constructor.unusedParameter

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
    $importSource = DataSource::factory()->create([
        'source_type' => $driverClass,
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
    $importSource = fakeDataSource($driverClass, ['tracking_number' => 'tracking', 'shipment_reference' => 'ref']);

    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'data_source_id' => $importSource->id,
        'shipment_reference' => 'REF-A',
    ]);
    $pkg1 = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'exported' => false,
        'tracking_number' => 'TRACK-A',
    ]);

    $shipment2 = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'data_source_id' => $importSource->id,
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
        'data_source_id' => $importSource->id,
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
        ->expectsOutputToContain('No export-enabled data sources configured')
        ->assertExitCode(0);
});

it('exports to global export source even when package has no import source', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $driverClass = fakeExportSource();

    $globalSource = DataSource::factory()->create([
        'source_type' => $driverClass,
        'global_export' => true,
        'settings' => [
            'export_enabled' => true,
            'export_field_mapping' => ['tracking_number' => 'tracking'],
        ],
    ]);

    $package = createShippedPackage(); // no import source, no client

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(1);
    expect($result->destinationsSucceeded)->toBe(1);
    expect($driverClass::$exportedData)->toHaveCount(1);
    expect($driverClass::$exportedData[0])->toHaveKey('tracking');
});

it('exports to both primary source and global export source', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $driverClass = fakeExportSource();

    $primarySource = fakeDataSource($driverClass, ['tracking_number' => 'primary_tracking']);
    $globalSource = DataSource::factory()->create([
        'source_type' => $driverClass,
        'global_export' => true,
        'settings' => [
            'export_enabled' => true,
            'export_field_mapping' => ['tracking_number' => 'global_tracking'],
        ],
    ]);

    $package = createShippedPackage(importSource: $primarySource);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(2);
    expect($result->destinationsSucceeded)->toBe(2);
    expect($driverClass::$exportedData)->toHaveCount(2);

    $keys = array_column($driverClass::$exportedData, null);
    $allKeys = array_merge(...array_map('array_keys', $driverClass::$exportedData));
    expect($allKeys)->toContain('primary_tracking')->toContain('global_tracking');
});

it('does not double-export when global source is also the primary source', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $driverClass = fakeExportSource();

    $source = DataSource::factory()->create([
        'source_type' => $driverClass,
        'global_export' => true,
        'settings' => [
            'export_enabled' => true,
            'export_field_mapping' => ['tracking_number' => 'tracking'],
        ],
    ]);

    $package = createShippedPackage(importSource: $source);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->destinationsAttempted)->toBe(1); // not 2
    expect($driverClass::$exportedData)->toHaveCount(1);
});

it('does not fan out to global export sources in single-client mode', function (): void {
    $driverClass = fakeExportSource();

    DataSource::factory()->create([
        'source_type' => $driverClass,
        'global_export' => true,
        'settings' => [
            'export_enabled' => true,
            'export_field_mapping' => ['tracking_number' => 'tracking'],
        ],
    ]);

    $package = createShippedPackage(); // no import source

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(0);
    expect($driverClass::$exportedData)->toHaveCount(0);
});

it('still exports to the primary source in single-client mode', function (): void {
    $driverClass = fakeExportSource();

    $primarySource = fakeDataSource($driverClass, ['tracking_number' => 'tracking']);

    $package = createShippedPackage(importSource: $primarySource);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(1);
    expect($driverClass::$exportedData)->toHaveCount(1);
});

it('skips inactive global export sources', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $driverClass = fakeExportSource();

    DataSource::factory()->create([
        'source_type' => $driverClass,
        'global_export' => true,
        'active' => false,
        'settings' => ['export_enabled' => true],
    ]);

    $package = createShippedPackage();

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(0);
    expect($driverClass::$exportedData)->toHaveCount(0);
});

it('skips global export sources where export_enabled is false', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $driverClass = fakeExportSource();

    DataSource::factory()->create([
        'source_type' => $driverClass,
        'global_export' => true,
        'active' => true,
        'settings' => ['export_enabled' => false],
    ]);

    $package = createShippedPackage();

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(0);
    expect($driverClass::$exportedData)->toHaveCount(0);
});
