<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'is_default' => false,
            'active' => true,
        ];
    }

    /**
     * A client that has consented to postage bought with no price and no
     * service reported back. See ADR-0003 decision 5.
     */
    public function withBlindPurchase(): static
    {
        return $this->state(fn () => ['blind_purchase_enabled' => true]);
    }

    public function default(): static
    {
        return $this->state(fn () => [
            'name' => 'Default Client',
            'is_default' => true,
            'active' => true,
        ]);
    }
}
