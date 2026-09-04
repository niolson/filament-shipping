<?php

namespace Database\Factories;

use App\Enums\SourceEnvironment;
use App\Models\CarrierService;
use App\Models\ObservedService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObservedService>
 */
class ObservedServiceFactory extends Factory
{
    protected $model = ObservedService::class;

    public function definition(): array
    {
        return [
            'source' => 'amazon',
            'environment' => SourceEnvironment::Production,
            'marketplace' => 'ATVPDKIKX0DER',
            'external_carrier_id' => 'ONTRAC',
            'external_carrier_name' => 'OnTrac',
            'external_service_id' => 'ONTRAC_MFN_GROUND',
            'external_service_name' => 'OnTrac Ground',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'last_eligible_at' => now(),
            'observation_count' => 1,
        ];
    }

    public function mapped(?CarrierService $carrierService = null): static
    {
        return $this->state(fn (): array => [
            'carrier_service_id' => $carrierService?->id ?? CarrierService::factory(),
        ]);
    }

    /**
     * Seen only in an `ineligibleRates` array — a real identity we have never
     * been offered the chance to buy.
     */
    public function neverEligible(): static
    {
        return $this->state(fn (): array => [
            'last_eligible_at' => null,
        ]);
    }
}
