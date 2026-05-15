<?php

use App\Filament\Widgets\DeviceStatusWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders without errors', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DeviceStatusWidget::class)
        ->assertStatus(200);
});
