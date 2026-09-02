<?php

namespace Database\Factories;

use App\Models\Carrier;
use App\Models\CarrierAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarrierAlias>
 */
class CarrierAliasFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'carrier_id' => Carrier::factory(),
            'alias' => fake()->unique()->company(),
        ];
    }
}
