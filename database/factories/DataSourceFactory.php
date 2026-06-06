<?php

namespace Database\Factories;

use App\Models\DataSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSource>
 */
class DataSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'driver' => DatabaseSource::class,
            'active' => true,
            'global_export' => false,
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

    public function globalExport(): static
    {
        return $this->state([
            'driver' => DatabaseSource::class,
            'global_export' => true,
            'settings' => ['export_enabled' => true],
        ]);
    }
}
