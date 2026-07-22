<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the profile page inside a section', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditProfile::class)
        ->assertSuccessful()
        ->assertSeeHtml('fi-section-content-ctn');
});

it('shows the live password policy checklist on the profile page', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditProfile::class)
        ->assertSee('Password must include:')
        ->assertSee('At least 12 characters')
        ->assertSee('At least one symbol');
});

it('enforces the password policy when changing password from the profile page', function (): void {
    $user = User::factory()->admin()->create(['email' => 'admin@example.test', 'password' => Hash::make('CurrentPass123!456')]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'short1!',
            'passwordConfirmation' => 'short1!',
            'currentPassword' => 'CurrentPass123!456',
        ])
        ->call('save')
        ->assertHasFormErrors(['password']);
});

it('rejects reusing the current password from the profile page', function (): void {
    $user = User::factory()->admin()->create(['email' => 'admin@example.test', 'password' => Hash::make('CurrentPass123!456')]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'CurrentPass123!456',
            'passwordConfirmation' => 'CurrentPass123!456',
            'currentPassword' => 'CurrentPass123!456',
        ])
        ->call('save')
        ->assertHasFormErrors(['password']);
});

it('accepts a strong, unused new password from the profile page', function (): void {
    $user = User::factory()->admin()->create(['email' => 'admin@example.test', 'password' => Hash::make('CurrentPass123!456')]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'BrandNewPass987!654',
            'passwordConfirmation' => 'BrandNewPass987!654',
            'currentPassword' => 'CurrentPass123!456',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('BrandNewPass987!654', $user->fresh()->password))->toBeTrue();
});
