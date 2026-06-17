<?php

use App\Filament\Auth\EmailAuthenticationForEmailUsers;
use App\Models\User;

it('enables email authentication for users with email authentication enabled and an email address', function () {
    $user = new User([
        'email' => 'admin@example.com',
    ]);
    $user->has_email_authentication = true;

    expect(EmailAuthenticationForEmailUsers::make()->isEnabled($user))->toBeTrue();
});

it('does not enable email authentication for users without an email address', function () {
    $user = new User([
        'email' => null,
    ]);
    $user->has_email_authentication = true;

    expect(EmailAuthenticationForEmailUsers::make()->isEnabled($user))->toBeFalse();
});
