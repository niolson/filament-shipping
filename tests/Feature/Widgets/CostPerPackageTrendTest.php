<?php

use App\Filament\Widgets\CostPerPackageTrend;
use App\Models\DailyShippingStat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

it('renders with no data', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CostPerPackageTrend::class)
        ->assertSee('Avg Cost Per Package');
});

it('renders with daily shipping stats', function (): void {
    DailyShippingStat::create([
        'date' => now()->toDateString(),
        'package_count' => 10,
        'total_cost' => '125.00',
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CostPerPackageTrend::class)
        ->assertSee('Avg Cost Per Package')
        ->assertSee('Daily average over the last 30 days');
});

it('averages over priced packages and says how many it left out', function (): void {
    DailyShippingStat::create([
        'date' => now()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 6,
        'total_cost' => '60.00',
        'total_weight' => '20.00',
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CostPerPackageTrend::class)
        ->assertSee('excluding 4 packages with no reported cost');

    expect((new CostPerPackageTrend)->getDescription())
        ->toBe('Daily average over the last 30 days, excluding 4 packages with no reported cost');
});

it('plots no point on a day where nothing reported a cost', function (): void {
    DailyShippingStat::create([
        'date' => now()->toDateString(),
        'package_count' => 4,
        'costed_package_count' => 0,
        'total_cost' => '0.00',
        'total_weight' => '8.00',
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CostPerPackageTrend::class)
        ->assertSee('Avg Cost Per Package');

    // A day whose every label was unpriced has no known average — the series
    // gaps rather than dipping to zero.
    $chart = (fn (): array => $this->getData())->call(new CostPerPackageTrend);

    expect(array_filter($chart['datasets'][0]['data'], fn ($point): bool => $point !== null))->toBeEmpty();
});
