<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the profile page inside a section', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditProfile::class)
        ->assertSuccessful()
        ->assertSeeHtml('fi-section-content-ctn');
});
