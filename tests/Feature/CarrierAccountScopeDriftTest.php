<?php

use App\Filament\Resources\CarrierAccounts\Pages\CreateCarrierAccount;
use App\Filament\Resources\CarrierAccounts\Pages\EditCarrierAccount;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Client;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * `carrier_account_scopes.carrier_id` is denormalized so the unique index can
 * enforce one account per (carrier, location, client). The scope derives it in
 * its own `saving` hook, which cannot see the account changing carriers — so
 * the account has to keep it in step.
 */
function driftedAccount(string $from = 'USPS', string $to = 'FedEx'): array
{
    $fromCarrier = Carrier::firstOrCreate(['name' => $from]);
    $toCarrier = Carrier::firstOrCreate(['name' => $to]);

    $account = CarrierAccount::create([
        'carrier_id' => $fromCarrier->id,
        'name' => "{$from} account",
        'active' => true,
    ]);

    return [$account, $fromCarrier, $toCarrier];
}

function runScopeRestampMigration(): void
{
    $migration = require database_path('migrations/2026_09_03_204500_restamp_drifted_carrier_account_scopes.php');

    $migration->up();
}

it('moves an account scope to the carrier the account moved to', function (): void {
    [$account, $usps, $fedex] = driftedAccount();

    $scope = CarrierAccountScope::create([
        'carrier_account_id' => $account->id,
        'location_id' => null,
        'client_id' => null,
        'rate_shop' => false,
    ]);

    $account->update(['carrier_id' => $fedex->id]);

    // Before this fix the scope kept carrier_id = USPS, so the account resolved
    // for USPS shipments and vanished from FedEx ones.
    expect($scope->refresh()->carrier_id)->toBe($fedex->id)
        ->and(CarrierAccount::resolveForShipment($fedex->id, null, null)->pluck('id')->all())->toBe([$account->id])
        ->and(CarrierAccount::resolveForShipment($usps->id, null, null))->toBeEmpty();
});

it('moves every scope the account holds, not just the global one', function (): void {
    [$account, , $fedex] = driftedAccount();
    $location = Location::factory()->create();
    $client = Client::factory()->create();

    foreach ([[null, null], [$location->id, null], [null, $client->id], [$location->id, $client->id]] as [$locationId, $clientId]) {
        CarrierAccountScope::create([
            'carrier_account_id' => $account->id,
            'location_id' => $locationId,
            'client_id' => $clientId,
            'rate_shop' => false,
        ]);
    }

    $account->update(['carrier_id' => $fedex->id]);

    expect($account->scopes()->pluck('carrier_id')->unique()->all())->toBe([$fedex->id]);
});

it('drops a scope whose slot is already held on the new carrier rather than colliding', function (): void {
    [$account, , $fedex] = driftedAccount();

    $incumbent = CarrierAccount::create([
        'carrier_id' => $fedex->id,
        'name' => 'Incumbent FedEx',
        'active' => true,
    ]);
    CarrierAccountScope::create([
        'carrier_account_id' => $incumbent->id,
        'location_id' => null,
        'client_id' => null,
        'rate_shop' => false,
    ]);

    $movedScope = CarrierAccountScope::create([
        'carrier_account_id' => $account->id,
        'location_id' => null,
        'client_id' => null,
        'rate_shop' => false,
    ]);

    $account->update(['carrier_id' => $fedex->id]);

    // The index would reject the move, and a row naming a carrier its account
    // left can only resolve wrongly — so the account is left visibly unscoped
    // and the incumbent still wins.
    expect(CarrierAccountScope::find($movedScope->id))->toBeNull()
        ->and($account->scopes()->count())->toBe(0)
        ->and(CarrierAccount::resolveForShipment($fedex->id, null, null)->pluck('id')->all())->toBe([$incumbent->id]);
});

it('leaves scopes alone when the account keeps its carrier', function (): void {
    [$account, $usps] = driftedAccount();

    $scope = CarrierAccountScope::create([
        'carrier_account_id' => $account->id,
        'location_id' => null,
        'client_id' => null,
        'rate_shop' => false,
    ]);

    $account->update(['name' => 'Renamed', 'active' => true]);

    expect($scope->refresh()->carrier_id)->toBe($usps->id)
        ->and($account->scopes()->count())->toBe(1);
});

describe('the one-time repair for rows that drifted before the hook existed', function (): void {
    it('restamps a scope whose account had already moved', function (): void {
        [$account, , $fedex] = driftedAccount();
        $scope = CarrierAccountScope::create([
            'carrier_account_id' => $account->id,
            'location_id' => null,
            'client_id' => null,
            'rate_shop' => false,
        ]);

        // Bypass the model so the row drifts the way it used to.
        DB::table('carrier_accounts')->where('id', $account->id)->update(['carrier_id' => $fedex->id]);

        expect($scope->refresh()->carrier_id)->not->toBe($fedex->id);

        runScopeRestampMigration();
        runScopeRestampMigration();

        expect($scope->refresh()->carrier_id)->toBe($fedex->id);
    });

    it('drops a drifted scope whose slot was taken in the meantime', function (): void {
        [$account, , $fedex] = driftedAccount();
        $scope = CarrierAccountScope::create([
            'carrier_account_id' => $account->id,
            'location_id' => null,
            'client_id' => null,
            'rate_shop' => false,
        ]);

        $incumbent = CarrierAccount::create([
            'carrier_id' => $fedex->id,
            'name' => 'Incumbent FedEx',
            'active' => true,
        ]);
        CarrierAccountScope::create([
            'carrier_account_id' => $incumbent->id,
            'location_id' => null,
            'client_id' => null,
            'rate_shop' => false,
        ]);

        DB::table('carrier_accounts')->where('id', $account->id)->update(['carrier_id' => $fedex->id]);

        runScopeRestampMigration();

        expect(CarrierAccountScope::find($scope->id))->toBeNull()
            ->and(CarrierAccount::resolveForShipment($fedex->id, null, null)->pluck('id')->all())->toBe([$incumbent->id]);
    });
});

it('fixes the carrier once an account exists, so credentials cannot outlive it', function (): void {
    [$account] = driftedAccount();
    $this->actingAs(User::factory()->admin()->create());

    // Not only the scope drift: the credentials below the field are issued by
    // this carrier and mean nothing to another one, so the account would be
    // left unable to authenticate either way.
    Livewire::test(EditCarrierAccount::class, ['record' => $account->id])
        ->assertFormFieldDisabled('carrier_id');

    Livewire::test(CreateCarrierAccount::class)
        ->assertFormFieldEnabled('carrier_id');
});
