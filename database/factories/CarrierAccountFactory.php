<?php

namespace Database\Factories;

use App\Models\Carrier;
use App\Models\CarrierAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarrierAccount>
 */
class CarrierAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'carrier_id' => Carrier::factory(),
            'name' => fake()->words(3, true),
            'active' => true,
        ];
    }

    public function usps(): static
    {
        return $this->state(fn () => [
            'carrier_id' => Carrier::factory()->usps(),
            'name' => 'USPS Account',
            'credentials' => [
                'crid' => fake()->numerify('########'),
                'mid' => fake()->numerify('#########'),
                'eps_account' => fake()->numerify('########'),
            ],
        ]);
    }

    public function fedex(): static
    {
        return $this->state(fn () => [
            'carrier_id' => Carrier::factory()->fedex(),
            'name' => 'FedEx Account',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
