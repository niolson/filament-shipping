<?php

namespace App\Services;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Finalizes an SSO login, enforcing the app's multi-factor requirement.
 *
 * SSO callbacks authenticate the user directly, which historically skipped
 * Filament's login-time MFA challenge entirely — an SSO user with an enrolled
 * authenticator reached the panel with no code. This service closes that gap.
 *
 * When MFA is required and the user has an enabled factor, authentication is
 * **deferred**: the user is *not* logged in (no session, no remember cookie).
 * Only the pending user id is stashed in the session and the login page renders
 * an MFA challenge (see Login::mount / Login::authenticate). Authentication and
 * the remember cookie happen only after the challenge succeeds. Deferring — rather
 * than logging in and gating with middleware — means an incomplete challenge
 * leaves nothing authenticated, so no route (Filament or otherwise) is reachable
 * and no persistent cookie exists to bypass the challenge. Fail closed.
 */
class SsoLoginService
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function completeLogin(User $user): RedirectResponse
    {
        if ($this->requiresMfaChallenge($user)) {
            session()->put('sso_mfa.user_id', $user->getAuthIdentifier());
            session()->put('sso_mfa.remember', true);

            return redirect()->route('filament.app.auth.login');
        }

        Auth::login($user, remember: true);

        return redirect('/');
    }

    /**
     * MFA must be challenged when the panel requires it AND the user has at
     * least one enabled MFA provider. A user with no enrolled factor is left
     * to Filament's own enrollment gate (EnsureMultiFactorAuthenticationIsEnabled),
     * which forces setup after login exactly as it does for password users.
     */
    public function requiresMfaChallenge(User $user): bool
    {
        if (! $this->settings->get('require_mfa', false)) {
            return false;
        }

        foreach (Filament::getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider->isEnabled($user)) {
                return true;
            }
        }

        return false;
    }
}
