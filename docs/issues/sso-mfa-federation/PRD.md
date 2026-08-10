# SSO-aware MFA (federated MFA via IdP `amr`)

Status: shipped (Entra); Google blocked on Google-side enablement
Type: AFK

## Status (2026-07-24)

All three slices merged to `main` in #92 (squash `0f07df5`).

- **Slice 01 (enforcement gate)** — done. SSO logins no longer bypass the app MFA requirement (deferred auth).
- **Slice 02 (broker `amr` plumbing + capture)** — done and validated end-to-end against real tokens (`capture-findings.md`).
- **Slice 03 (trust IdP MFA / the skip)** — done, **Azure/Entra only**, fail-closed behind a per-tenant `trust_idp_mfa` setting + trusted-`tid` allowlist. Ready to enable for a tenant with admin-enforced Entra MFA (Settings → Authentication; add trusted tid `fc6cceae-6ffd-432e-97cb-ad02ed2367f7`).

**Google is the one open thread — blocked on Google's side, not ours.** Requesting the Security-bundle `amr`/`auth_time` claims still triggers a **500 on `accounts.google.com` during a live 2-step sign-in** (a cached/no-MFA login succeeds). Confirmed 2026-07-24 with **all prerequisites met**: app In-production, brand-verified, non-sensitive scopes (full verification not required), and both Advanced-Settings toggles (Session age + Authentication strength) enabled. Toggling the broker flag `GOOGLE_SECURITY_BUNDLE_CLAIMS=true` reproduced the 500; set back to `false` and Google login works. Presumed Google per-project enablement still propagating. The claim request is gated off by default in the broker ([[project_google_security_bundle_claims]]); the app code is provider-agnostic, so Google needs no code change once it works. **Next: retest the flag in a few days (~2026-07-28+).**

## Problem

Two related gaps in how SSO logins interact with the app's multi-factor requirement:

1. **SSO bypasses MFA entirely (security bug).** All three SSO paths — `GoogleController::callback`, `AzureController::callback`, `SsoCallbackController::receive` — call `Auth::login($user, remember: true)` directly and redirect to `/`. That never touches Filament's login-time MFA challenge ([Login.php:110-149](../../vendor/filament/filament/src/Auth/Pages/Login.php)). Filament's `require_mfa`/`isRequired` only activates the `EnsureMultiFactorAuthenticationIsEnabled` middleware, which enforces *enrollment* (a factor exists), never *exercise* (the factor was used this session). Net effect with `require_mfa` on: an SSO user who has enrolled TOTP still logs in with **no code, every time**.

2. **No credit for MFA the IdP already performed.** When the identity provider asserts MFA via the OIDC `amr` claim (`amr` contains `"mfa"`), the app should be able to treat the second factor as satisfied and skip its own challenge — instead of double-prompting.

## Approach

- **Fail closed.** Missing/absent `amr` always falls through to the app's own MFA challenge, never to admittance. Absence means "not asserted," not "MFA didn't happen."
- **Custom post-login gate, not Filament's built-in.** Filament's challenge is welded into the password-login Livewire component (it re-checks password credentials after the code at [Login.php:151](../../vendor/filament/filament/src/Auth/Pages/Login.php)); SSO users have no local password, so that path can't be reused. We build a small SSO-only gate that reuses only the public verification primitives (`AppAuthentication::verifyCode()` / `verifyRecoveryCode()`).
- **Per-tenant trust.** IdP-MFA trust is opt-in per instance and only credible for admin-enforced tenants (Entra Conditional Access; Google Workspace admin-enforced MFA). Consumer Google 2SV is a bonus signal, never an enforced gate.

## Provider notes

- **Entra:** `amr` carries `mfa`; the broker's `AzureOAuthProvider` already decodes the ID token. Verify `amr` appears in v2.0 tokens (may need optional-claims config).
- **Google:** `amr` (+ `auth_time`) shipped June 2026 via the "Security bundle" — opt-in, requires app In-Production + brand-verified with the Session-age and Authentication-strength toggles on, and must be explicitly requested via the `claims` param. No `acr` claim; normalize on `amr` + `auth_time`. Trustworthy for Workspace (admin-enforced), weak for consumer Gmail. **Status 2026-07-24:** all prerequisites met but the `claims` request still 500s on a live 2-step sign-in — a Google-side enablement/propagation issue, not a request-shape problem (softening `essential` → voluntary did not help). Gated off via `GOOGLE_SECURITY_BUNDLE_CLAIMS` (default false) in the broker; retest in a few days.

## Slices

1. ✅ `issues/01-sso-mfa-enforcement-gate.md` — close the bypass (fail-closed, no `amr`) — **done**
2. ✅ `issues/02-broker-amr-plumbing.md` — pass `amr`/`auth_time` through the broker (Azure + Google) + log-only capture — **done**
3. ✅ `issues/03-trust-idp-mfa-skip.md` — skip the app challenge when the IdP asserts MFA; per-tenant setting — **done (Entra only; Google turns on once its Security-bundle 500 clears)**

## Notes

- `capture-findings.md` — real-login capture results (2026-07-23): what Google/Azure actually return, the Entra optional-claims requirement, and the concrete trusted/excluded tenant IDs.
