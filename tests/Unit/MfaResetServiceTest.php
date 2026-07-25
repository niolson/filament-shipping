<?php

use App\Models\User;
use App\Services\MfaResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('detects app authentication enrollment', function (): void {
    $enrolled = User::factory()->create(['app_authentication_secret' => 'secret']);
    $notEnrolled = User::factory()->create();

    $service = app(MfaResetService::class);

    expect($service->hasAppAuthentication($enrolled))->toBeTrue()
        ->and($service->hasAppAuthentication($notEnrolled))->toBeFalse();
});

it('detects email authentication enrollment', function (): void {
    $enrolled = User::factory()->create(['has_email_authentication' => true]);
    $notEnrolled = User::factory()->create(['has_email_authentication' => false]);

    $service = app(MfaResetService::class);

    expect($service->hasEmailAuthentication($enrolled))->toBeTrue()
        ->and($service->hasEmailAuthentication($notEnrolled))->toBeFalse();
});

it('clears the authenticator app secret and recovery codes on reset', function (): void {
    $user = User::factory()->create([
        'app_authentication_secret' => 'secret',
        'app_authentication_recovery_codes' => ['code-1', 'code-2'],
    ]);

    app(MfaResetService::class)->resetAppAuthentication($user);

    $user->refresh();
    expect($user->app_authentication_secret)->toBeNull()
        ->and($user->app_authentication_recovery_codes)->toBeNull();
});

it('disables email authentication on reset', function (): void {
    $user = User::factory()->create(['has_email_authentication' => true]);

    app(MfaResetService::class)->resetEmailAuthentication($user);

    expect($user->fresh()->hasEmailAuthentication())->toBeFalse();
});
