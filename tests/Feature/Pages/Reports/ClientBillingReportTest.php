<?php

use App\Enums\Role;
use App\Filament\Pages\Reports\ClientBillingReport;
use App\Models\Client;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function billingRow(ClientBillingReport $page, int $clientId): ?object
{
    $method = new ReflectionMethod($page, 'summaryQuery');

    return $method->invoke($page)->where('clients.id', $clientId)->first();
}

beforeEach(function (): void {
    // SQLite lacks GREATEST(); register it so the billing queries work in tests.
    DB::connection()->getPdo()->sqliteCreateFunction(
        'GREATEST',
        fn () => max(func_get_args()),
        -1
    );

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    Setting::create(['key' => 'multi_client_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

it('renders the client billing report page', function (): void {
    Livewire::test(ClientBillingReport::class)
        ->assertOk()
        ->assertSee('Client Billing');
});

it('restricts access when multi_client_enabled is false', function (): void {
    Setting::where('key', 'multi_client_enabled')->update(['value' => '0']);
    app(SettingsService::class)->clearCache();

    expect(ClientBillingReport::canAccess())->toBeFalse();
});

it('restricts access for non-manager roles', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));

    expect(ClientBillingReport::canAccess())->toBeFalse();
});

it('calculates label fee per package', function (): void {
    $client = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '2.00',
        'pick_fee_first_item' => '0.00',
        'pick_fee_additional_item' => '0.00',
    ]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '10.00']);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '10.00']);

    $page = Livewire::test(ClientBillingReport::class)
        ->instance();

    $row = billingRow($page, $client->id);

    expect((float) $row->label_fees)->toBe(4.00)  // 2 packages × $2.00
        ->and((int) $row->package_count)->toBe(2);
});

it('calculates first-item pick fee for a single-item shipment', function (): void {
    $client = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '0.00',
        'pick_fee_first_item' => '3.00',
        'pick_fee_additional_item' => '1.00',
    ]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 1]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '5.00']);

    $page = Livewire::test(ClientBillingReport::class)
        ->instance();

    $row = billingRow($page, $client->id);

    // First item $3.00, no additional items
    expect((float) $row->pick_fees)->toBe(3.00);
});

it('calculates additional item pick fees for multi-item shipments', function (): void {
    $client = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '0.00',
        'pick_fee_first_item' => '3.00',
        'pick_fee_additional_item' => '1.00',
    ]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 3]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '5.00']);

    $page = Livewire::test(ClientBillingReport::class)
        ->instance();

    $row = billingRow($page, $client->id);

    // First item $3.00 + (3 - 1) × $1.00 = $5.00
    expect((float) $row->pick_fees)->toBe(5.00);
});

it('sums all fee types into total_billable', function (): void {
    $client = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '1.00',
        'pick_fee_first_item' => '2.00',
        'pick_fee_additional_item' => '0.50',
    ]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'quantity' => 3]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '10.00']);

    $page = Livewire::test(ClientBillingReport::class)
        ->instance();

    $row = billingRow($page, $client->id);

    // postage $10 + label $1 + pick_base $2 + pick_extra (2 × $0.50) $1 = $14
    expect((float) $row->total_billable)->toBe(14.00);
});

it('only includes shipped packages in billing totals', function (): void {
    $client = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '1.00',
        'pick_fee_first_item' => '0.00',
        'pick_fee_additional_item' => '0.00',
    ]);

    $shipment = Shipment::factory()->create(['client_id' => $client->id]);
    Package::factory()->shipped()->create(['shipment_id' => $shipment->id, 'cost' => '10.00']);
    Package::factory()->create(['shipment_id' => $shipment->id]); // unshipped, should not count

    $page = Livewire::test(ClientBillingReport::class)
        ->instance();

    $row = billingRow($page, $client->id);

    expect((int) $row->package_count)->toBe(1)
        ->and((float) $row->label_fees)->toBe(1.00);
});

it('excludes inactive clients from the summary', function (): void {
    $client = Client::factory()->create(['is_default' => false, 'active' => false]);

    $page = Livewire::test(ClientBillingReport::class)
        ->instance();

    $row = billingRow($page, $client->id);

    expect($row)->toBeNull();
});

it('does not include another clients shipments in billing row', function (): void {
    $clientA = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '1.00',
        'pick_fee_first_item' => '0.00',
        'pick_fee_additional_item' => '0.00',
    ]);
    $clientB = Client::factory()->create([
        'is_default' => false,
        'label_fee_per_package' => '1.00',
        'pick_fee_first_item' => '0.00',
        'pick_fee_additional_item' => '0.00',
    ]);

    $shipmentA = Shipment::factory()->create(['client_id' => $clientA->id]);
    Package::factory()->shipped()->create(['shipment_id' => $shipmentA->id]);

    $shipmentB = Shipment::factory()->create(['client_id' => $clientB->id]);
    Package::factory()->shipped()->create(['shipment_id' => $shipmentB->id]);
    Package::factory()->shipped()->create(['shipment_id' => $shipmentB->id]);

    $page = Livewire::test(ClientBillingReport::class)
        ->instance();

    $rowA = billingRow($page, $clientA->id);
    $rowB = billingRow($page, $clientB->id);

    expect((int) $rowA->package_count)->toBe(1)
        ->and((int) $rowB->package_count)->toBe(2);
});
