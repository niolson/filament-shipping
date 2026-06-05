<?php

use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Client;
use App\Models\Location;

it('derives carrier_id from the account on create', function (): void {
    $account = CarrierAccount::factory()->create();

    $scope = CarrierAccountScope::create([
        'carrier_account_id' => $account->id,
        'location_id' => null,
        'client_id' => null,
        'rate_shop' => false,
    ]);

    expect($scope->fresh()->carrier_id)->toBe($account->carrier_id);
});

it('updates carrier_id when carrier_account_id is changed', function (): void {
    $accountA = CarrierAccount::factory()->create();
    $accountB = CarrierAccount::factory()->create();

    $scope = CarrierAccountScope::factory()->forAccount($accountA)->create();

    $scope->carrier_account_id = $accountB->id;
    $scope->save();

    expect($scope->fresh()->carrier_id)->toBe($accountB->carrier_id);
});

it('resolveForShipment returns empty collection when no active accounts match', function (): void {
    $account = CarrierAccount::factory()->inactive()->create();
    CarrierAccountScope::factory()->forAccount($account)->global()->create();

    $result = CarrierAccount::resolveForShipment($account->carrier_id, null, null);

    expect($result)->toBeEmpty();
});

it('resolveForShipment returns global default when no specific scopes match', function (): void {
    $account = CarrierAccount::factory()->create();
    CarrierAccountScope::factory()->forAccount($account)->global()->create();

    $result = CarrierAccount::resolveForShipment($account->carrier_id, null, null);

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($account->id);
});

it('resolveForShipment prefers location+client over location-only scope', function (): void {
    $location = Location::factory()->create();
    $client = Client::factory()->create();

    $locationAccount = CarrierAccount::factory()->create();
    CarrierAccountScope::factory()->forAccount($locationAccount)->locationScoped($location)->create();

    $specificAccount = CarrierAccount::factory()->create([
        'carrier_id' => $locationAccount->carrier_id,
    ]);
    CarrierAccountScope::factory()
        ->forAccount($specificAccount)
        ->locationScoped($location)
        ->clientScoped($client)
        ->create();

    $result = CarrierAccount::resolveForShipment($specificAccount->carrier_id, $location->id, $client->id);

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($specificAccount->id);
});

it('resolveForShipment prefers location-only scope over client-only scope', function (): void {
    $location = Location::factory()->create();
    $client = Client::factory()->create();

    $locationAccount = CarrierAccount::factory()->create();
    CarrierAccountScope::factory()->forAccount($locationAccount)->locationScoped($location)->create();

    $clientAccount = CarrierAccount::factory()->create([
        'carrier_id' => $locationAccount->carrier_id,
    ]);
    CarrierAccountScope::factory()->forAccount($clientAccount)->clientScoped($client)->create();

    $result = CarrierAccount::resolveForShipment($locationAccount->carrier_id, $location->id, $client->id);

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($locationAccount->id);
});

it('resolveForShipment includes location default when winning scope has rate_shop enabled', function (): void {
    $location = Location::factory()->create();
    $client = Client::factory()->create();

    $primaryAccount = CarrierAccount::factory()->create();
    CarrierAccountScope::factory()
        ->forAccount($primaryAccount)
        ->locationScoped($location)
        ->clientScoped($client)
        ->withRateShop()
        ->create();

    $locationAccount = CarrierAccount::factory()->create([
        'carrier_id' => $primaryAccount->carrier_id,
    ]);
    CarrierAccountScope::factory()
        ->forAccount($locationAccount)
        ->locationScoped($location)
        ->create();

    $result = CarrierAccount::resolveForShipment($primaryAccount->carrier_id, $location->id, $client->id);

    expect($result)->toHaveCount(2)
        ->and($result->pluck('id')->all())->toContain($primaryAccount->id)
        ->and($result->pluck('id')->all())->toContain($locationAccount->id);
});

it('resolveForShipment does not include location default for rate_shop when location is null', function (): void {
    $client = Client::factory()->create();

    $account = CarrierAccount::factory()->create();
    CarrierAccountScope::factory()
        ->forAccount($account)
        ->clientScoped($client)
        ->withRateShop()
        ->create();

    $result = CarrierAccount::resolveForShipment($account->carrier_id, null, $client->id);

    expect($result)->toHaveCount(1);
});
