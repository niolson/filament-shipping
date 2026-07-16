<?php

use App\Filament\Pages\Auth\ChangePassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('enforces the password policy on the forced change-password page', function (): void {
    $user = User::factory()->create(['password' => Hash::make('CurrentPass123!456')]);
    $this->actingAs($user)->withSession(['password_expired' => true]);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'CurrentPass123!456',
            'password' => 'short1!',
            'password_confirmation' => 'short1!',
        ])
        ->call('changePassword')
        ->assertHasFormErrors(['password']);
});

it('rejects reusing the current password on the forced change-password page', function (): void {
    $user = User::factory()->create(['password' => Hash::make('CurrentPass123!456')]);
    $this->actingAs($user)->withSession(['password_expired' => true]);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'CurrentPass123!456',
            'password' => 'CurrentPass123!456',
            'password_confirmation' => 'CurrentPass123!456',
        ])
        ->call('changePassword')
        ->assertHasFormErrors(['password']);
});

it('accepts a strong, unused new password on the forced change-password page', function (): void {
    $user = User::factory()->create(['password' => Hash::make('CurrentPass123!456')]);
    $this->actingAs($user)->withSession(['password_expired' => true]);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'CurrentPass123!456',
            'password' => 'BrandNewPass987!654',
            'password_confirmation' => 'BrandNewPass987!654',
        ])
        ->call('changePassword')
        ->assertHasNoFormErrors();

    expect(Hash::check('BrandNewPass987!654', $user->fresh()->password))->toBeTrue();
});
