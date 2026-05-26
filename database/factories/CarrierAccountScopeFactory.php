<?php

namespace Database\Factories;

use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Client;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarrierAccountScope>
 */
class CarrierAccountScopeFactory extends Factory
{
    public function definition(): array
    {
        $account = CarrierAccount::factory()->create();

        return [
            'carrier_account_id' => $account->id,
            'carrier_id' => $account->carrier_id,
            'location_id' => null,
            'client_id' => null,
            'rate_shop' => false,
        ];
    }

    public function forAccount(CarrierAccount $account): static
    {
        return $this->state(fn () => [
            'carrier_account_id' => $account->id,
            'carrier_id' => $account->carrier_id,
        ]);
    }

    public function locationScoped(Location $location): static
    {
        return $this->state(fn () => ['location_id' => $location->id]);
    }

    public function clientScoped(Client $client): static
    {
        return $this->state(fn () => ['client_id' => $client->id]);
    }

    public function global(): static
    {
        return $this->state(fn () => ['location_id' => null, 'client_id' => null]);
    }

    public function withRateShop(): static
    {
        return $this->state(fn () => ['rate_shop' => true]);
    }
}
