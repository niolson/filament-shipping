<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\SsoLoginService;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * Enroll an app-authenticator (TOTP) secret on a user and return the secret.
 */
function enrollTotp(User $user): string
{
    $secret = AppAuthentication::make()->generateSecret();
    $user->app_authentication_secret = $secret;
    $user->save();

    return $secret;
}

function requireMfa(bool $enabled = true): void
{
    app(SettingsService::class)->set('require_mfa', $enabled);
}

// --- SsoLoginService: the single MFA decision point -------------------------

it('logs in and does not gate when MFA is not required', function () {
    requireMfa(false);
    $user = User::factory()->create();
    enrollTotp($user);

    $response = app(SsoLoginService::class)->completeLogin($user);

    expect(Auth::check())->toBeTrue()
        ->and(session('sso_mfa.user_id'))->toBeNull()
        ->and($response->getTargetUrl())->toBe(url('/'));
});

it('defers authentication when MFA is required and a factor is enrolled', function () {
    requireMfa();
    $user = User::factory()->create();
    enrollTotp($user);

    $response = app(SsoLoginService::class)->completeLogin($user);

    // Finding #1/#2: the user must NOT be authenticated while the challenge is
    // pending — no session auth and no remember cookie exist to bypass MFA.
    expect(Auth::check())->toBeFalse()
        ->and(session('sso_mfa.user_id'))->toBe($user->id)
        ->and($response->getTargetUrl())->toContain('/login')
        ->and($response->headers->getCookies())->toBe([]);
});

it('does not gate when MFA is required but the user has no enrolled factor', function () {
    requireMfa();
    $user = User::factory()->create();

    $response = app(SsoLoginService::class)->completeLogin($user);

    // Left to Filament's own enrollment gate; no challenge is deferred here.
    expect(Auth::check())->toBeTrue()
        ->and(session('sso_mfa.user_id'))->toBeNull()
        ->and($response->getTargetUrl())->toBe(url('/'));
});

it('treats an email-authentication user as requiring a challenge', function () {
    requireMfa();
    $user = User::factory()->create([
        'email' => 'ops@example.com',
        'has_email_authentication' => true,
    ]);

    expect(app(SsoLoginService::class)->requiresMfaChallenge($user))->toBeTrue();
});

// --- Deferred-auth protection: pending user can't reach the app -------------

it('bounces a pending SSO user from an authenticated route to the login challenge', function () {
    requireMfa();
    $user = User::factory()->create();
    enrollTotp($user);

    // Pending state, not authenticated (as SsoLoginService leaves it).
    $this->withSession(['sso_mfa.user_id' => $user->id, 'sso_mfa.remember' => true]);

    // A non-Filament authenticated route (the finding-#2 leak) must not be reachable.
    $this->get('/qz/provision-script/windows')->assertRedirect();
    expect(Auth::check())->toBeFalse();
});

// --- Login page SSO challenge -----------------------------------------------

it('renders the MFA challenge on the login page for a pending SSO user', function () {
    requireMfa();
    $user = User::factory()->create();
    enrollTotp($user);
    session()->put('sso_mfa.user_id', $user->id);
    session()->put('sso_mfa.remember', true);

    Livewire::test(Login::class)
        ->assertSet('userUndertakingMultiFactorAuthentication', fn ($value) => filled($value));

    expect(Auth::check())->toBeFalse();
});

it('authenticates only after a valid TOTP code is submitted', function () {
    requireMfa();
    $user = User::factory()->create();
    $secret = enrollTotp($user);
    session()->put('sso_mfa.user_id', $user->id);
    session()->put('sso_mfa.remember', true);

    $code = AppAuthentication::make()->getCurrentCode($user, $secret);

    Livewire::test(Login::class)
        ->set('data.multiFactor.app.code', $code)
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(Auth::check())->toBeTrue()
        ->and(Auth::id())->toBe($user->id)
        ->and(session('sso_mfa.user_id'))->toBeNull();
});

it('rejects an invalid TOTP code and stays unauthenticated', function () {
    requireMfa();
    $user = User::factory()->create();
    enrollTotp($user);
    session()->put('sso_mfa.user_id', $user->id);
    session()->put('sso_mfa.remember', true);

    Livewire::test(Login::class)
        ->set('data.multiFactor.app.code', '000000')
        ->call('authenticate')
        ->assertHasErrors();

    expect(Auth::check())->toBeFalse()
        ->and(session('sso_mfa.user_id'))->toBe($user->id);
});

it('rejects a stale challenge when a second SSO attempt changed the pending user', function () {
    requireMfa();
    $userA = User::factory()->create();
    $secretA = enrollTotp($userA);
    $userB = User::factory()->create();
    enrollTotp($userB);

    // Tab 1: challenge mounted for user A.
    session()->put('sso_mfa.user_id', $userA->id);
    session()->put('sso_mfa.remember', true);
    $tabOne = Livewire::test(Login::class);

    // Tab 2: a second SSO attempt swaps the pending session user to B.
    session()->put('sso_mfa.user_id', $userB->id);

    // Submitting A's valid code in Tab 1 must NOT authenticate B (confused deputy).
    $codeA = AppAuthentication::make()->getCurrentCode($userA, $secretA);

    $tabOne
        ->set('data.multiFactor.app.code', $codeA)
        ->call('authenticate')
        ->assertHasErrors();

    expect(Auth::check())->toBeFalse()
        ->and(session('sso_mfa.user_id'))->toBeNull();
});

it('accepts a recovery code', function () {
    requireMfa();
    $user = User::factory()->create();
    enrollTotp($user);
    $plainCode = 'aaaa11111-bbbb22222';
    $user->app_authentication_recovery_codes = [Hash::make($plainCode), Hash::make('cccc33333-dddd44444')];
    $user->save();
    session()->put('sso_mfa.user_id', $user->id);
    session()->put('sso_mfa.remember', true);

    Livewire::test(Login::class)
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', $plainCode)
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(Auth::check())->toBeTrue()
        ->and(session('sso_mfa.user_id'))->toBeNull();
});
