<?php

use App\Filament\Pages\Auth\ChangePassword;
use App\Http\Middleware\EnsurePasswordNotExpired;
use Illuminate\Http\Request;
use Livewire\Livewire;

function requestWithExpiredFlag(string $uri, bool $expired = true): Request
{
    $session = app('session.store');
    $session->put('password_expired', $expired);

    $request = Request::create($uri, 'GET');
    $request->setLaravelSession($session);

    return $request;
}

it('redirects to the change-password page when the password is expired', function (): void {
    $response = app(EnsurePasswordNotExpired::class)->handle(
        requestWithExpiredFlag('/'),
        fn () => response('ok'),
    );

    expect($response->isRedirect())->toBeTrue()
        ->and($response->headers->get('Location'))->toBe(ChangePassword::getUrl());
});

it('redirects away from any other panel page while the password is expired', function (): void {
    $response = app(EnsurePasswordNotExpired::class)->handle(
        requestWithExpiredFlag('/ship/1'),
        fn () => response('ok'),
    );

    expect($response->isRedirect())->toBeTrue()
        ->and($response->headers->get('Location'))->toBe(ChangePassword::getUrl());
});

it('allows the change-password page itself through', function (): void {
    $response = app(EnsurePasswordNotExpired::class)->handle(
        requestWithExpiredFlag('/auth/change-password'),
        fn () => response('ok'),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('ok');
});

it('allows logout through so the user can sign out', function (): void {
    $response = app(EnsurePasswordNotExpired::class)->handle(
        requestWithExpiredFlag('/logout'),
        fn () => response('ok'),
    );

    expect($response->getStatusCode())->toBe(200);
});

it('allows a livewire update replayed against the change-password page through', function (): void {
    // Registered as Livewire persistent middleware, this guard runs against the
    // reconstructed original page path (memo.path). For the change-password page
    // that path is exempt, so its form submissions are allowed.
    $response = app(EnsurePasswordNotExpired::class)->handle(
        requestWithExpiredFlag('/auth/change-password'),
        fn () => response('ok'),
    );

    expect($response->getStatusCode())->toBe(200);
});

it('redirects a livewire update replayed against any other page', function (): void {
    // A stale component snapshot on another page replays with that page's path,
    // which is not exempt, so the action is redirected instead of executed.
    $response = app(EnsurePasswordNotExpired::class)->handle(
        requestWithExpiredFlag('/manual-ship'),
        fn () => response('ok'),
    );

    expect($response->isRedirect())->toBeTrue()
        ->and($response->headers->get('Location'))->toBe(ChangePassword::getUrl());
});

it('is registered as Livewire persistent middleware so it replays on component updates', function (): void {
    expect(Livewire::getPersistentMiddleware())->toContain(EnsurePasswordNotExpired::class);
});

it('allows requests through when the password is not expired', function (): void {
    $response = app(EnsurePasswordNotExpired::class)->handle(
        requestWithExpiredFlag('/', expired: false),
        fn () => response('ok'),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('ok');
});
