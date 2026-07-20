<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Services\AccountLockoutService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function attemptLogin(string $email, string $password): void
{
    Livewire::test(Login::class)
        ->fillForm([
            'login' => $email,
            'password' => $password,
        ])
        ->call('authenticate');
}

it('locks the account after the configured number of consecutive failed attempts', function (): void {
    $user = User::factory()->create([
        'email' => 'lockout@example.com',
        'password' => bcrypt('correct-horse'),
    ]);

    for ($i = 0; $i < AccountLockoutService::DEFAULT_MAX_ATTEMPTS; $i++) {
        attemptLogin('lockout@example.com', 'wrong-password');
    }

    $user->refresh();
    expect($user->failed_login_attempts)->toBe(AccountLockoutService::DEFAULT_MAX_ATTEMPTS)
        ->and($user->locked_until)->not->toBeNull()
        ->and($user->locked_until->isFuture())->toBeTrue();

    // Even the correct password is rejected while locked.
    attemptLogin('lockout@example.com', 'correct-horse');

    expect(auth()->check())->toBeFalse();
});

it('resets the failed attempt counter after a successful login', function (): void {
    $user = User::factory()->create([
        'email' => 'reset@example.com',
        'password' => bcrypt('correct-horse'),
    ]);

    attemptLogin('reset@example.com', 'wrong-password');
    attemptLogin('reset@example.com', 'wrong-password');

    expect($user->refresh()->failed_login_attempts)->toBe(2);

    attemptLogin('reset@example.com', 'correct-horse');

    $user->refresh();
    expect($user->failed_login_attempts)->toBe(0)
        ->and($user->locked_until)->toBeNull()
        ->and(auth()->check())->toBeTrue();
});

it('honors a configurable lockout threshold', function (): void {
    app(SettingsService::class)->set('account_lockout_max_attempts', 2, 'integer');

    $user = User::factory()->create([
        'email' => 'configurable@example.com',
        'password' => bcrypt('correct-horse'),
    ]);

    attemptLogin('configurable@example.com', 'wrong-password');
    expect($user->refresh()->locked_until)->toBeNull();

    attemptLogin('configurable@example.com', 'wrong-password');
    expect($user->refresh()->locked_until)->not->toBeNull();
});

it('allows login again once the lockout duration has elapsed', function (): void {
    $user = User::factory()->create([
        'email' => 'expires@example.com',
        'password' => bcrypt('correct-horse'),
    ]);

    $user->forceFill([
        'failed_login_attempts' => AccountLockoutService::DEFAULT_MAX_ATTEMPTS,
        'locked_until' => now()->subMinute(),
    ])->save();

    expect(app(AccountLockoutService::class)->isLocked($user))->toBeFalse();

    attemptLogin('expires@example.com', 'correct-horse');

    expect(auth()->check())->toBeTrue();
});

it('lets an admin unlock an account early via AccountLockoutService', function (): void {
    $user = User::factory()->create([
        'failed_login_attempts' => AccountLockoutService::DEFAULT_MAX_ATTEMPTS,
        'locked_until' => now()->addMinutes(15),
    ]);

    $lockout = app(AccountLockoutService::class);
    expect($lockout->isLocked($user))->toBeTrue();

    $lockout->resetAttempts($user);

    $user->refresh();
    expect($lockout->isLocked($user))->toBeFalse()
        ->and($user->failed_login_attempts)->toBe(0)
        ->and($user->locked_until)->toBeNull();
});
