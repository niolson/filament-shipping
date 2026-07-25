<?php

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows which mfa methods are enabled on the user edit page', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $user = User::factory()->create([
        'app_authentication_secret' => 'secret',
        'has_email_authentication' => true,
    ]);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->assertSee('Authenticator App')
        ->assertSee('Email Code');
});

it('shows not enabled when the user has no mfa configured', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $user = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->assertSee('Not enabled');
});

it('lets an admin reset a user\'s authenticator app enrollment', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $user = User::factory()->create([
        'app_authentication_secret' => 'secret',
        'app_authentication_recovery_codes' => ['code-1'],
    ]);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->assertActionVisible('resetAppAuthentication')
        ->callAction('resetAppAuthentication')
        ->assertNotified();

    $user->refresh();
    expect($user->app_authentication_secret)->toBeNull()
        ->and($user->app_authentication_recovery_codes)->toBeNull();
});

it('lets an admin reset a user\'s email code authentication', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $user = User::factory()->create(['has_email_authentication' => true]);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->assertActionVisible('resetEmailAuthentication')
        ->callAction('resetEmailAuthentication')
        ->assertNotified();

    expect($user->fresh()->hasEmailAuthentication())->toBeFalse();
});

it('hides the reset actions when the corresponding mfa method is not enabled', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $user = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->assertActionHidden('resetAppAuthentication')
        ->assertActionHidden('resetEmailAuthentication');
});
