<?php

namespace Database\Factories;

use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Services\ClientContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingMethodAlias>
 */
class ShippingMethodAliasFactory extends Factory
{
    protected $model = ShippingMethodAlias::class;

    public function definition(): array
    {
        return [
            'client_id' => app(ClientContext::class)->id(),
            'reference' => fake()->unique()->slug(2),
            'shipping_method_id' => ShippingMethod::factory(),
        ];
    }
}
