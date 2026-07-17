<?php

use App\Models\User;
use App\Services\PasswordPolicyService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

it('enforces the tightened default policy: 12 chars incl. a symbol', function (): void {
    $rule = app(PasswordPolicyService::class)->rule();

    // 8 chars, mixed case + number but no symbol — fails under the new defaults.
    expect(Validator::make(['password' => 'Abc12345'], ['password' => $rule])->fails())->toBeTrue();

    // 12+ chars with mixed case, number, and a symbol — passes.
    expect(Validator::make(['password' => 'Abcdef123456!'], ['password' => $rule])->fails())->toBeFalse();
});

it('expires a local password older than the default 90-day window', function (): void {
    $service = app(PasswordPolicyService::class);

    // Set password_changed_at in a separate write: the model resets it to now()
    // whenever the password itself is dirty (i.e. on the initial create).
    $stale = User::factory()->create();
    $stale->update(['password_changed_at' => now()->subDays(100)]);

    $fresh = User::factory()->create();
    $fresh->update(['password_changed_at' => now()->subDays(10)]);

    expect($service->isPasswordExpired($stale))->toBeTrue();
    expect($service->isPasswordExpired($fresh))->toBeFalse();
});

it('respects an explicit override of the expiration setting', function (): void {
    app(SettingsService::class)->set('password_expiration_days', 0, 'integer');

    $service = app(PasswordPolicyService::class);
    $stale = User::factory()->create(['password_changed_at' => now()->subDays(999)]);

    expect($service->isPasswordExpired($stale))->toBeFalse();
});

it('records the replaced password to history and rejects reusing it', function (): void {
    $service = app(PasswordPolicyService::class);

    $user = User::factory()->create(['password' => Hash::make('Original123!456')]);

    expect($user->passwordHistories()->count())->toBe(0);

    $user->update(['password' => Hash::make('Different456!789')]);

    expect($user->passwordHistories()->count())->toBe(1)
        ->and($service->isPasswordReused($user->fresh(), 'Original123!456'))->toBeTrue()
        ->and($service->isPasswordReused($user->fresh(), 'Different456!789'))->toBeTrue()
        ->and($service->isPasswordReused($user->fresh(), 'BrandNew789!012'))->toBeFalse();
});

it('prunes password history beyond the configured limit', function (): void {
    app(SettingsService::class)->set('password_history_count', 3, 'integer');
    $service = app(PasswordPolicyService::class);

    $user = User::factory()->create(['password' => Hash::make('Password0!23456')]);

    foreach (range(1, 4) as $i) {
        $user->update(['password' => Hash::make("Password{$i}!23456")]);
    }

    // Limit 3 keeps the current password + 2 history rows.
    expect($user->passwordHistories()->count())->toBe(2);

    // The oldest replaced passwords should have aged out and be reusable again.
    expect($service->isPasswordReused($user->fresh(), 'Password0!23456'))->toBeFalse()
        ->and($service->isPasswordReused($user->fresh(), 'Password3!23456'))->toBeTrue()
        ->and($service->isPasswordReused($user->fresh(), 'Password4!23456'))->toBeTrue();
});

it('disables history checks when the setting is 0', function (): void {
    app(SettingsService::class)->set('password_history_count', 0, 'integer');
    $service = app(PasswordPolicyService::class);

    $user = User::factory()->create(['password' => Hash::make('Original123!456')]);

    expect($service->isPasswordReused($user, 'Original123!456'))->toBeFalse();
});
