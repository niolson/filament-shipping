<?php

use App\Enums\PickBatchStatus;
use App\Enums\Role;
use App\Filament\Resources\PickBatches\Pages\ViewPickBatch;
use App\Filament\Resources\PickBatches\RelationManagers\PickBatchShipmentsRelationManager;
use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    Setting::create(['key' => 'picking_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

it('tells the shipments relation manager to refresh after marking all picked', function (): void {
    $batch = PickBatch::factory()->create(['total_shipments' => 1]);
    PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id]);

    $page = Livewire::test(ViewPickBatch::class, ['record' => $batch->id])
        ->callAction('complete')
        ->assertDispatched('pick-batch-updated');

    // The event must be broadcast globally, not scoped to the page component: Livewire
    // dispatches a bubbling DOM event that reaches `window`, which is the only way the
    // nested relation manager's listener hears it. A `self` or `component` key here
    // would narrow delivery and leave the shipments table stale.
    $dispatch = collect($page->effects['dispatches'] ?? [])
        ->firstWhere('name', 'pick-batch-updated');

    expect($dispatch)->not->toBeNull()
        ->and($dispatch)->not->toHaveKey('self')
        ->and($dispatch)->not->toHaveKey('component');

    expect($batch->fresh()->status)->toBe(PickBatchStatus::Completed);
});

it('shows picked rows after the relation manager handles the refresh event', function (): void {
    $batch = PickBatch::factory()->create(['total_shipments' => 1]);
    $pivot = PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id]);

    $relationManager = Livewire::test(PickBatchShipmentsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewPickBatch::class,
    ]);

    $relationManager->assertTableColumnStateSet('picked_at', null, $pivot);

    Livewire::test(ViewPickBatch::class, ['record' => $batch->id])->callAction('complete');

    $relationManager
        ->dispatch('pick-batch-updated')
        ->assertTableColumnStateNotSet('picked_at', null, $pivot);
});

it('refreshes the batch on the page when the relation manager completes it', function (): void {
    $batch = PickBatch::factory()->create(['total_shipments' => 1]);
    $pivot = PickBatchShipment::factory()->create(['pick_batch_id' => $batch->id]);

    Livewire::test(PickBatchShipmentsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewPickBatch::class,
    ])
        ->callAction(TestAction::make('markPicked')->table($pivot))
        ->assertDispatched('pick-batch-updated');

    expect($batch->fresh()->status)->toBe(PickBatchStatus::Completed);
});
