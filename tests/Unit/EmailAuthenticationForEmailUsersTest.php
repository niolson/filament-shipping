<?php

use App\Filament\Auth\EmailAuthenticationForEmailUsers;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('enables email authentication for users with email authentication enabled and an email address', function (): void {
    $user = new User([
        'email' => 'admin@example.com',
    ]);
    $user->has_email_authentication = true;

    expect(EmailAuthenticationForEmailUsers::make()->isEnabled($user))->toBeTrue();
});

it('does not enable email authentication for users without an email address', function (): void {
    $user = new User([
        'email' => null,
    ]);
    $user->has_email_authentication = true;

    expect(EmailAuthenticationForEmailUsers::make()->isEnabled($user))->toBeFalse();
});

it('rejects a code that is past its expiry', function (): void {
    session([
        'filament_email_authentication_code' => Hash::make('123456'),
        'filament_email_authentication_code_expires_at' => now()->subMinute(),
    ]);

    expect(EmailAuthenticationForEmailUsers::make()->verifyCode('123456'))->toBeFalse();
});

it('tells the user a rejected code may be expired rather than only wrong', function (string $key): void {
    expect(__($key))->toBe('The code you entered is invalid or expired.');
})->with([
    'filament-panels::auth/multi-factor/email/provider.login_form.code.messages.invalid',
    'filament-panels::auth/multi-factor/email/actions/set-up.modal.form.code.messages.invalid',
    'filament-panels::auth/multi-factor/email/actions/disable.modal.form.code.messages.invalid',
]);
