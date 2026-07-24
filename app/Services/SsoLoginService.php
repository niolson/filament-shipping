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

    /**
     * Microsoft's well-known consumer (personal-account) tenant. MSA accounts
     * emit no `amr` and can never satisfy MFA; excluded defensively regardless
     * of the operator allowlist.
     */
    private const AZURE_CONSUMER_TID = '9188040d-6c67-4c5b-b112-36a304b66dad';

    /**
     * @param  string  $provider  the IdP the login came through ('google', 'azure',
     *                            or 'password' for a non-federated caller)
     * @param  array<string, mixed>  $idpClaims  the broker's *verified* `extra`
     *                                           payload (amr / auth_time / tid);
     *                                           empty for direct (unverified) callbacks
     */
    public function completeLogin(User $user, string $provider = 'password', array $idpClaims = []): RedirectResponse
    {
        if ($this->requiresMfaChallenge($user) && ! $this->idpSatisfiesMfa($provider, $idpClaims)) {
            session()->put('sso_mfa.user_id', $user->getAuthIdentifier());
            session()->put('sso_mfa.remember', true);

            return redirect()->route('filament.app.auth.login');
        }

        Auth::login($user, remember: true);

        return redirect('/');
    }

    /**
     * Whether the identity provider's own assertion should stand in for the app's
     * MFA challenge, letting a federated MFA satisfy the second factor instead of
     * double-prompting. Opt-in per tenant (`trust_idp_mfa`), Azure/Entra only for
     * now, and — because the shared broker app registration accepts any Microsoft
     * tenant — gated on an allowlist of trusted `tid`s.
     *
     * Fails closed: setting off, wrong provider, no `amr:mfa`, a missing/consumer/
     * untrusted `tid`, or an empty allowlist all return false, leaving the app's
     * own challenge in force. Absence is never treated as satisfaction.
     *
     * @param  array<string, mixed>  $idpClaims  the broker's verified `extra`
     */
    public function idpSatisfiesMfa(string $provider, array $idpClaims): bool
    {
        if (! $this->settings->get('trust_idp_mfa', false)) {
            return false;
        }

        // Only Entra is trusted in this cut; Google's `amr` is not yet credible
        // (see the sso-mfa-federation PRD — consumer 2SV is per-event and weak).
        if ($provider !== 'azure') {
            return false;
        }

        $amr = $idpClaims['amr'] ?? null;

        if (! is_array($amr) || ! in_array('mfa', $amr, true)) {
            return false;
        }

        $tid = $idpClaims['tid'] ?? null;

        if (! is_string($tid) || trim($tid) === '') {
            return false;
        }

        // Entra tenant ids are case-insensitive UUIDs; compare canonically so an
        // uppercase GUID pasted into Settings still matches Entra's lowercase tid.
        $tid = strtolower(trim($tid));

        if ($tid === self::AZURE_CONSUMER_TID) {
            return false;
        }

        return in_array($tid, $this->trustedAzureTids(), true);
    }

    /**
     * The operator-configured allowlist of trusted Entra tenant ids, canonicalized
     * to lowercase for case-insensitive matching. An empty list trusts nothing
     * (fail closed).
     *
     * @return list<string>
     */
    private function trustedAzureTids(): array
    {
        $configured = $this->settings->get('trusted_azure_tids', []);

        if (is_string($configured)) {
            $configured = json_decode($configured, true) ?: [];
        }

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($tid): string => strtolower(trim((string) $tid)), $configured),
            fn (string $tid): bool => $tid !== '',
        ));
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
