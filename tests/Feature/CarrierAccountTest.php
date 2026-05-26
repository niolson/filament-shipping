<?php

use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Client;
use App\Models\Location;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

describe('CarrierAccount::resolveForShipment', function (): void {
    beforeEach(function (): void {
        $this->usps = Carrier::factory()->usps()->create();
        $this->location = Location::factory()->create();
        $this->client = Client::factory()->create();
    });

    it('returns the global default when no specific scope matches', function (): void {
        $account = CarrierAccount::factory()->usps()->create(['carrier_id' => $this->usps->id]);
        CarrierAccountScope::factory()->forAccount($account)->global()->create();

        $result = CarrierAccount::resolveForShipment($this->usps->id, $this->location->id, null);

        expect($result)->toHaveCount(1)
            ->and($result->first()->id)->toBe($account->id);
    });

    it('prefers location scope over global default', function (): void {
        $global = CarrierAccount::factory()->usps()->create(['carrier_id' => $this->usps->id, 'name' => 'Global']);
        CarrierAccountScope::factory()->forAccount($global)->global()->create();

        $locationAccount = CarrierAccount::factory()->usps()->create(['carrier_id' => $this->usps->id, 'name' => 'Location']);
        CarrierAccountScope::factory()->forAccount($locationAccount)->locationScoped($this->location)->create();

        $result = CarrierAccount::resolveForShipment($this->usps->id, $this->location->id, null);

        expect($result->first()->id)->toBe($locationAccount->id);
    });

    it('prefers client+location scope over location-only scope', function (): void {
        $locationAccount = CarrierAccount::factory()->usps()->create(['carrier_id' => $this->usps->id, 'name' => 'Location']);
        CarrierAccountScope::factory()->forAccount($locationAccount)->locationScoped($this->location)->create();

        $clientAccount = CarrierAccount::factory()->usps()->create(['carrier_id' => $this->usps->id, 'name' => 'Client']);
        CarrierAccountScope::factory()
            ->forAccount($clientAccount)
            ->locationScoped($this->location)
            ->clientScoped($this->client)
            ->create();

        $result = CarrierAccount::resolveForShipment($this->usps->id, $this->location->id, $this->client->id);

        expect($result->first()->id)->toBe($clientAccount->id);
    });

    it('returns two accounts when rate_shop is enabled on the client scope', function (): void {
        $locationAccount = CarrierAccount::factory()->usps()->create(['carrier_id' => $this->usps->id, 'name' => 'Location']);
        CarrierAccountScope::factory()->forAccount($locationAccount)->locationScoped($this->location)->create();

        $clientAccount = CarrierAccount::factory()->usps()->create(['carrier_id' => $this->usps->id, 'name' => 'Client']);
        CarrierAccountScope::factory()
            ->forAccount($clientAccount)
            ->locationScoped($this->location)
            ->clientScoped($this->client)
            ->withRateShop()
            ->create();

        $result = CarrierAccount::resolveForShipment($this->usps->id, $this->location->id, $this->client->id);

        expect($result)->toHaveCount(2)
            ->and($result->first()->id)->toBe($clientAccount->id)
            ->and($result->last()->id)->toBe($locationAccount->id);
    });

    it('returns empty collection when no account exists', function (): void {
        $result = CarrierAccount::resolveForShipment($this->usps->id, $this->location->id, null);

        expect($result)->toBeEmpty();
    });

    it('excludes inactive accounts', function (): void {
        $account = CarrierAccount::factory()->usps()->inactive()->create(['carrier_id' => $this->usps->id]);
        CarrierAccountScope::factory()->forAccount($account)->locationScoped($this->location)->create();

        $result = CarrierAccount::resolveForShipment($this->usps->id, $this->location->id, null);

        expect($result)->toBeEmpty();
    });
});

describe('CarrierAccountScope uniqueness constraint', function (): void {
    beforeEach(function (): void {
        $this->usps = Carrier::factory()->usps()->create();
    });

    it('prevents duplicate global-scope rows for the same carrier', function (): void {
        $account = CarrierAccount::factory()->usps()->create(['carrier_id' => $this->usps->id]);
        CarrierAccountScope::factory()->forAccount($account)->global()->create();

        expect(fn () => CarrierAccountScope::factory()->forAccount($account)->global()->create())
            ->toThrow(QueryException::class);
    });

    it('prevents two accounts from claiming the same (carrier, location, client) slot', function (): void {
        $location = Location::factory()->create();
        $account1 = CarrierAccount::factory()->usps()->create(['carrier_id' => $this->usps->id]);
        $account2 = CarrierAccount::factory()->usps()->create(['carrier_id' => $this->usps->id]);

        CarrierAccountScope::factory()->forAccount($account1)->locationScoped($location)->create();

        expect(fn () => CarrierAccountScope::factory()->forAccount($account2)->locationScoped($location)->create())
            ->toThrow(QueryException::class);
    });

    it('allows the same account at different locations', function (): void {
        $location1 = Location::factory()->create();
        $location2 = Location::factory()->create();
        $account = CarrierAccount::factory()->usps()->create(['carrier_id' => $this->usps->id]);

        CarrierAccountScope::factory()->forAccount($account)->locationScoped($location1)->create();
        $scope2 = CarrierAccountScope::factory()->forAccount($account)->locationScoped($location2)->create();

        expect($scope2->exists)->toBeTrue();
    });
});

describe('CarrierAccount cache invalidation', function (): void {
    it('clears token cache keyed by account ID when USPS credentials change', function (): void {
        $usps = Carrier::factory()->usps()->create();
        $account = CarrierAccount::factory()->usps()->create(['carrier_id' => $usps->id]);

        $cacheKey = "usps_payment_authorization_token:{$account->id}";
        Cache::put($cacheKey, 'old-token', 3600);

        $account->update(['credentials' => ['crid' => 'new-crid']]);

        expect(Cache::has($cacheKey))->toBeFalse();
    });

    it('does not affect other accounts when credentials change', function (): void {
        $usps = Carrier::factory()->usps()->create();
        $account1 = CarrierAccount::factory()->usps()->create(['carrier_id' => $usps->id]);
        $account2 = CarrierAccount::factory()->usps()->create(['carrier_id' => $usps->id]);

        Cache::put("usps_payment_authorization_token:{$account2->id}", 'untouched', 3600);

        $account1->update(['credentials' => ['crid' => 'new-crid']]);

        expect(Cache::has("usps_payment_authorization_token:{$account2->id}"))->toBeTrue();
    });
});
