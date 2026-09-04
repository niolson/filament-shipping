<?php

use App\Models\User;
use App\Services\SettingsService;
use App\Services\SsoLoginService;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\Auth;

/**
 * Slice 03 — trust the IdP's asserted MFA (`amr`) to skip the app's own challenge.
 * Azure/Entra only, gated on a trusted-`tid` allowlist. Everything here is a
 * fail-closed check: absence, wrong provider, or an untrusted tenant must leave
 * the app challenge in force.
 */
const TRUSTED_TID = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
const MSA_TID = '9188040d-6c67-4c5b-b112-36a304b66dad';

function trustIdpMfa(bool $enabled = true): void
{
    app(SettingsService::class)->set('trust_idp_mfa', $enabled);
}

/**
 * @param  list<string>  $tids
 */
function trustAzureTids(array $tids): void
{
    app(SettingsService::class)->set('trusted_azure_tids', $tids, type: 'json');
}

/**
 * Broker `extra` payload for an Entra login.
 *
 * @param  list<string>  $amr
 * @return array<string, mixed>
 */
function azureClaims(array $amr = ['pwd', 'totp', 'mfa'], string $tid = TRUSTED_TID, ?int $authTime = null): array
{
    return array_filter([
        'amr' => $amr,
        'tid' => $tid,
        'auth_time' => $authTime,
    ], fn ($value): bool => $value !== null);
}

function ssoService(): SsoLoginService
{
    return app(SsoLoginService::class);
}

/**
 * Self-contained factor enrollment + require_mfa toggle so this file does not
 * depend on helpers defined in a sibling test file (which breaks when run in
 * isolation). Uniquely named to avoid colliding with SsoMfaChallengeTest.php.
 */
function enrollAppFactor(User $user): void
{
    $user->app_authentication_secret = AppAuthentication::make()->generateSecret();
    $user->save();
}

function mfaRequired(): void
{
    app(SettingsService::class)->set('require_mfa', true);
}

// --- idpSatisfiesMfa: the security decision, in isolation -------------------

it('is satisfied for a trusted Entra tenant asserting amr:mfa', function () {
    trustIdpMfa();
    trustAzureTids([TRUSTED_TID]);

    expect(ssoService()->idpSatisfiesMfa('azure', azureClaims()))->toBeTrue();
});

it('is not satisfied when the trust setting is off', function () {
    trustIdpMfa(false);
    trustAzureTids([TRUSTED_TID]);

    expect(ssoService()->idpSatisfiesMfa('azure', azureClaims()))->toBeFalse();
});

it('is not satisfied for a non-Azure provider even with amr:mfa', function () {
    trustIdpMfa();
    trustAzureTids([TRUSTED_TID]);

    expect(ssoService()->idpSatisfiesMfa('google', azureClaims()))->toBeFalse();
});

it('is not satisfied when amr does not contain mfa', function () {
    trustIdpMfa();
    trustAzureTids([TRUSTED_TID]);

    expect(ssoService()->idpSatisfiesMfa('azure', azureClaims(amr: ['pwd'])))->toBeFalse();
});

it('is not satisfied when the tenant is not on the allowlist', function () {
    trustIdpMfa();
    trustAzureTids([TRUSTED_TID]);

    expect(ssoService()->idpSatisfiesMfa('azure', azureClaims(tid: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')))->toBeFalse();
});

it('is not satisfied when the allowlist is empty (fail closed)', function () {
    trustIdpMfa();
    trustAzureTids([]);

    expect(ssoService()->idpSatisfiesMfa('azure', azureClaims()))->toBeFalse();
});

it('never trusts the Microsoft consumer tenant, even if allowlisted', function () {
    trustIdpMfa();
    trustAzureTids([MSA_TID]);

    expect(ssoService()->idpSatisfiesMfa('azure', azureClaims(tid: MSA_TID)))->toBeFalse();
});

it('is not satisfied when amr or tid is absent', function () {
    trustIdpMfa();
    trustAzureTids([TRUSTED_TID]);

    expect(ssoService()->idpSatisfiesMfa('azure', ['tid' => TRUSTED_TID]))->toBeFalse()
        ->and(ssoService()->idpSatisfiesMfa('azure', ['amr' => ['mfa']]))->toBeFalse()
        ->and(ssoService()->idpSatisfiesMfa('azure', []))->toBeFalse();
});

it('matches tenant ids case-insensitively (uppercase in the allowlist)', function () {
    trustIdpMfa();
    // Operator pastes an uppercase GUID; Entra asserts the canonical lowercase tid.
    trustAzureTids([strtoupper(TRUSTED_TID)]);

    expect(ssoService()->idpSatisfiesMfa('azure', azureClaims(tid: TRUSTED_TID)))->toBeTrue();
});

it('matches tenant ids case-insensitively (uppercase in the assertion)', function () {
    trustIdpMfa();
    trustAzureTids([TRUSTED_TID]);

    expect(ssoService()->idpSatisfiesMfa('azure', azureClaims(tid: strtoupper(TRUSTED_TID))))->toBeTrue();
});

it('ignores auth_time (freshness bound deferred) — a stale assertion still satisfies', function () {
    trustIdpMfa();
    trustAzureTids([TRUSTED_TID]);

    expect(ssoService()->idpSatisfiesMfa('azure', azureClaims(authTime: 1)))->toBeTrue();
});

// --- completeLogin: the wired outcome (skip vs. defer) ----------------------

it('skips the app challenge and logs in when a trusted IdP asserts MFA', function () {
    mfaRequired();
    trustIdpMfa();
    trustAzureTids([TRUSTED_TID]);
    $user = User::factory()->create();
    enrollAppFactor($user);

    $response = ssoService()->completeLogin($user, 'azure', azureClaims());

    expect(Auth::check())->toBeTrue()
        ->and(Auth::id())->toBe($user->id)
        ->and(session('sso_mfa.user_id'))->toBeNull()
        ->and($response->getTargetUrl())->toBe(url('/'));
});

it('still challenges when the IdP asserts only a password (no mfa)', function () {
    mfaRequired();
    trustIdpMfa();
    trustAzureTids([TRUSTED_TID]);
    $user = User::factory()->create();
    enrollAppFactor($user);

    $response = ssoService()->completeLogin($user, 'azure', azureClaims(amr: ['pwd']));

    expect(Auth::check())->toBeFalse()
        ->and(session('sso_mfa.user_id'))->toBe($user->id)
        ->and($response->getTargetUrl())->toContain('/login')
        ->and($response->headers->getCookies())->toBe([]);
});

it('still challenges when amr:mfa comes from an untrusted tenant', function () {
    mfaRequired();
    trustIdpMfa();
    trustAzureTids([TRUSTED_TID]);
    $user = User::factory()->create();
    enrollAppFactor($user);

    ssoService()->completeLogin($user, 'azure', azureClaims(tid: MSA_TID));

    expect(Auth::check())->toBeFalse()
        ->and(session('sso_mfa.user_id'))->toBe($user->id);
});

it('ignores the IdP assertion entirely when the trust setting is off', function () {
    mfaRequired();
    trustIdpMfa(false);
    trustAzureTids([TRUSTED_TID]);
    $user = User::factory()->create();
    enrollAppFactor($user);

    ssoService()->completeLogin($user, 'azure', azureClaims());

    expect(Auth::check())->toBeFalse()
        ->and(session('sso_mfa.user_id'))->toBe($user->id);
});

it('the trusted-tid allowlist round-trips through the settings service as a list', function () {
    trustAzureTids([TRUSTED_TID, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

    expect(app(SettingsService::class)->get('trusted_azure_tids'))
        ->toBe([TRUSTED_TID, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);
});
