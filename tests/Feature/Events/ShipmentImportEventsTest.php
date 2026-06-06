<?php

use App\Contracts\DataSourceInterface;
use App\Events\ImportCompleted;
use App\Events\ShipmentImported;
use App\Events\ShipmentUpdated;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\ShipmentImport\ShipmentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->dataSource = DataSource::factory()->create(['name' => 'Test Source']);
});

function importTestSource(Collection $shipments, Collection $items = new Collection): DataSourceInterface
{
    return new class($shipments, $items) implements DataSourceInterface
    {
        public function __construct(
            private Collection $shipments,
            private Collection $items,
        ) {}

        public function fetchShipments(): Collection
        {
            return $this->shipments;
        }

        public function fetchShipmentItems(string $sourceRecordId): Collection
        {
            return $this->items;
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
}

it('dispatches ShipmentImported for new shipments', function (): void {
    Event::fake([ShipmentImported::class, ShipmentUpdated::class, ImportCompleted::class]);

    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = importTestSource(collect([
        [
            'shipment_reference' => 'NEW-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address1' => '123 Main St',
            'city' => 'Seattle',
            'state_or_province' => 'WA',
            'postal_code' => '98101',
            'country' => 'US',
            'channel_id' => 'web',
        ],
    ]));

    ShipmentImportService::forSource($source, $this->dataSource)->import();

    Event::assertDispatched(ShipmentImported::class, function (ShipmentImported $event): bool {
        return $event->shipment->shipment_reference === 'NEW-001';
    });

    Event::assertNotDispatched(ShipmentUpdated::class);
});

it('dispatches ShipmentUpdated for existing shipments', function (): void {
    Event::fake([ShipmentImported::class, ShipmentUpdated::class, ImportCompleted::class]);

    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    Shipment::factory()->create([
        'shipment_reference' => 'EXISTING-001',
        'source_record_id' => 'EXISTING-001',
        'data_source_id' => $this->dataSource->id,
        'channel_id' => $channel->id,
    ]);

    $source = importTestSource(collect([
        [
            'shipment_reference' => 'EXISTING-001',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'address1' => '456 Oak Ave',
            'city' => 'Portland',
            'state_or_province' => 'OR',
            'postal_code' => '97201',
            'country' => 'US',
            'channel_id' => 'web',
        ],
    ]));

    ShipmentImportService::forSource($source, $this->dataSource)->import();

    Event::assertDispatched(ShipmentUpdated::class, function (ShipmentUpdated $event): bool {
        return $event->shipment->shipment_reference === 'EXISTING-001';
    });

    Event::assertNotDispatched(ShipmentImported::class);
});

it('dispatches ImportCompleted after import finishes', function (): void {
    Event::fake([ShipmentImported::class, ImportCompleted::class]);

    $channel = tap(Channel::factory()->create(), fn ($c) => ChannelAlias::create(['reference' => 'web', 'channel_id' => $c->id]));

    $source = importTestSource(collect([
        [
            'shipment_reference' => 'ORD-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address1' => '123 Main St',
            'city' => 'Seattle',
            'state_or_province' => 'WA',
            'postal_code' => '98101',
            'country' => 'US',
            'channel_id' => 'web',
        ],
    ]));

    ShipmentImportService::forSource($source, $this->dataSource)->import();

    Event::assertDispatched(ImportCompleted::class, function (ImportCompleted $event): bool {
        return $event->sourceName === 'Test Source'
            && $event->stats['shipments_created'] === 1;
    });
});
