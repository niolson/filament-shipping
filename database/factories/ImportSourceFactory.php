<?php

namespace Database\Factories;

use App\Models\ImportSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportSource>
 */
class ImportSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'driver' => DatabaseSource::class,
            'active' => true,
            'settings' => [],
            'secret_settings' => null,
        ];
    }

    public function shopify(): static
    {
        return $this->state([
            'driver' => ShopifySource::class,
            'settings' => [
                'shop_domain' => 'test.myshopify.com',
                'channel_name' => 'Shopify',
            ],
        ]);
    }
}
