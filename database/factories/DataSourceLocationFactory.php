<?php

namespace Database\Factories;

use App\Models\DataSource;
use App\Models\DataSourceLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSourceLocation>
 */
class DataSourceLocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'data_source_id' => DataSource::factory(),
            'external_id' => 'gid://shopify/Location/'.fake()->unique()->numberBetween(1000, 999999),
            'external_code' => null,
            'name' => fake()->company().' Warehouse',
            'address' => [
                'address1' => fake()->streetAddress(),
                'city' => fake()->city(),
                'provinceCode' => fake()->stateAbbr(),
                'zip' => fake()->postcode(),
                'countryCode' => 'US',
            ],
            'is_active' => true,
            'location_id' => null,
            'ignored_at' => null,
            'last_seen_at' => now(),
        ];
    }
}
