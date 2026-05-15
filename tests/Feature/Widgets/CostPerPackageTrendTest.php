<?php

use App\Filament\Widgets\CostPerPackageTrend;
use App\Models\DailyShippingStat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
