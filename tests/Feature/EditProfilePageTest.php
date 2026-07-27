<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use App\Services\SettingsService;
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
    $user->update(['password_changed_at' => now()->subDays(2)]);
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

it('blocks changing password from the profile page before the minimum age has elapsed', function (): void {
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
        ->assertHasFormErrors(['password']);

    expect(Hash::check('CurrentPass123!456', $user->fresh()->password))->toBeTrue();
});

it('allows changing password from the profile page once the minimum age setting is disabled', function (): void {
    app(SettingsService::class)->set('password_min_age_days', 0, 'integer');

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
