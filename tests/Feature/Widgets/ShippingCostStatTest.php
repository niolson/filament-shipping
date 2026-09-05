<?php

use App\Filament\Widgets\StatsOverview;
use App\Models\DailyShippingStat;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

it('shows shipping cost this week', function (): void {
    // Summary stats for this week with costs matching the expected total
    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 2,
        'total_cost' => 25.75,
        'total_weight' => 0,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('Shipping Cost This Week')
        ->assertSee('$25.75');
});

it('shows zero when no packages shipped this week', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('$0.00');
});

it('discloses packages the weekly cost could not price', function (): void {
    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 6,
        'total_cost' => 60.00,
        'total_weight' => 20.00,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('$60.00')
        ->assertSee('excludes 4 with no reported cost');
});

it('leaves the weekly cost unqualified when every package was priced', function (): void {
    DailyShippingStat::create([
        'date' => today()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 10,
        'total_cost' => 60.00,
        'total_weight' => 20.00,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('$60.00')
        ->assertDontSee('no reported cost');
});

it('withholds the week-over-week change when last week left postage unpriced', function (): void {
    // Last week's total is a subtotal, so the percentage against it would
    // overstate the rise — and would look no different from a measured one.
    DailyShippingStat::create([
        'date' => now(Location::timezone())->subWeek()->startOfWeek()->addDay()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 4,
        'total_cost' => 40.00,
        'total_weight' => 20.00,
    ]);

    DailyShippingStat::create([
        'date' => now(Location::timezone())->startOfWeek()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 10,
        'total_cost' => 100.00,
        'total_weight' => 20.00,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('$100.00')
        ->assertSee('no comparison — last week excludes 6 with no reported cost')
        ->assertDontSee('vs last week');
});

it('names both weeks when neither priced everything', function (): void {
    DailyShippingStat::create([
        'date' => now(Location::timezone())->subWeek()->startOfWeek()->addDay()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 4,
        'total_cost' => 40.00,
        'total_weight' => 20.00,
    ]);

    DailyShippingStat::create([
        'date' => now(Location::timezone())->startOfWeek()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 7,
        'total_cost' => 70.00,
        'total_weight' => 20.00,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('excludes 3 this week and 6 last week with no reported cost')
        ->assertDontSee('vs last week');
});

it('still compares the weeks when both priced everything', function (): void {
    DailyShippingStat::create([
        'date' => now(Location::timezone())->subWeek()->startOfWeek()->addDay()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 10,
        'total_cost' => 50.00,
        'total_weight' => 20.00,
    ]);

    DailyShippingStat::create([
        'date' => now(Location::timezone())->startOfWeek()->toDateString(),
        'package_count' => 10,
        'costed_package_count' => 10,
        'total_cost' => 100.00,
        'total_weight' => 20.00,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(StatsOverview::class)
        ->assertSee('+100.0% vs last week')
        ->assertDontSee('no reported cost');
});
