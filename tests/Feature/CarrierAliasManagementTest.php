<?php

use App\Enums\Role;
use App\Filament\Resources\Carriers\Pages\EditCarrier;
use App\Filament\Resources\Carriers\RelationManagers\CarrierAliasesRelationManager;
use App\Models\Carrier;
use App\Models\CarrierAlias;
use App\Models\Package;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('shows editable normalization aliases on the carrier resource', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    $carrier = Carrier::factory()->usps()->create();
    $alias = CarrierAlias::factory()->for($carrier)->create(['alias' => 'US Postal Service']);

    $relationManager = Livewire::test(CarrierAliasesRelationManager::class, [
        'ownerRecord' => $carrier,
        'pageClass' => EditCarrier::class,
    ]);

    $relationManager->assertTableColumnStateSet('alias', 'US Postal Service', $alias);
});

it('rejects duplicate aliases and aliases owned by another canonical carrier', function (): void {
    $usps = Carrier::factory()->usps()->create();
    $fedex = Carrier::factory()->fedex()->create();
    CarrierAlias::factory()->for($usps)->create(['alias' => 'US Postal Service']);

    expect(fn () => CarrierAlias::factory()->for($fedex)->create(['alias' => ' us  postal service ']))
        ->toThrow(ValidationException::class)
        ->and(fn () => CarrierAlias::factory()->for($fedex)->create(['alias' => 'USPS']))
        ->toThrow(ValidationException::class);
});

it('shows duplicate alias validation on the relation manager field', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    $carrier = Carrier::factory()->usps()->create();
    CarrierAlias::factory()->for($carrier)->create(['alias' => 'US Postal Service']);

    Livewire::test(CarrierAliasesRelationManager::class, [
        'ownerRecord' => $carrier,
        'pageClass' => EditCarrier::class,
    ])
        ->callAction(TestAction::make(CreateAction::class)->table(), [
            'alias' => ' us  postal service ',
        ])
        ->assertHasActionErrors(['alias']);
});

it('prevents deleting a carrier snapshotted by a shipped package', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    $carrier = Carrier::factory()->usps()->create();
    Package::factory()->shipped()->create(['normalized_carrier_id' => $carrier->id]);

    Livewire::test(EditCarrier::class, ['record' => $carrier->id])
        ->callAction(DeleteAction::class)
        ->assertNotified('Cannot delete carrier');

    expect($carrier->fresh())->not->toBeNull();
});
