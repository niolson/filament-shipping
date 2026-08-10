# Trust IdP MFA to satisfy the app challenge (the skip) + per-tenant setting

Status: done
Category: enhancement
Type: AFK

## Implemented (2026-07-24, commit 3cef4ff on sso-mfa-enforcement)

Azure-only skip landed as decided below. `SsoLoginService::idpSatisfiesMfa()` is
the fail-closed decision point (setting `trust_idp_mfa`, provider === azure,
`in_array('mfa', $amr)`, `tid` on the operator allowlist, MSA tenant hard-excluded,
empty allowlist ⇒ trust nothing); `tid`s are compared case-insensitively (lowercased
on save + on compare, UUID-validated on input). Wired through `completeLogin($user,
$provider, $idpClaims)` — only the broker's verified claims can grant the skip; the
direct Socialite callbacks pass none. Settings surfaced in the Authentication section.
`auth_time` freshness deferred. Fail-closed matrix covered in `tests/Feature/SsoIdpMfaTrustTest.php`
+ UI tests in `tests/Feature/Filament/Pages/SettingsTest.php`. Operator enablement:
turn on the setting and add trusted tid `fc6cceae-6ffd-432e-97cb-ad02ed2367f7`.

## Decisions locked (2026-07-24)

The three open triage decisions are resolved (blocks 01 + 02 are shipped and 02 is
validated end-to-end against real tokens — see `capture-findings.md`):

1. **Provider scope: Azure/Entra only** in this cut. Only Entra `amr:mfa` (with a
   trusted-`tid` check) can satisfy MFA. Google stays fully governed by the app's
   own challenge — the decision point stays provider-agnostic (`in_array('mfa',
   $amr)`) so Google turns on later with no rework, once its Security-bundle
   actually emits `amr:mfa` (currently gated off; see
   [[project_google_security_bundle_claims]]).
2. **`auth_time` freshness bound: deferred** to a follow-up. Entra Conditional
   Access session lifetime already governs how long an MFA session stays valid;
   a second bound adds a knob and surprise re-challenges for little gain. Capture
   `auth_time` into the decision context but don't gate on it yet.
3. **Entra app registration stays `common`** (not narrowed to `organizations`).
   Consequence: personal MSA accounts still reach the flow, so the **`tid`
   allowlist is the sole trust boundary** — it must fail closed:
   - Empty/unset allowlist ⇒ **no** Azure login can satisfy MFA (feature-off
     semantics), even with `trust_idp_mfa` on.
   - The MSA consumer tenant `9188040d-6c67-4c5b-b112-36a304b66dad` is
     **hard-excluded in code** regardless of allowlist contents (it emits no `amr`
     anyway, but exclude defensively).
   - Trusted test-org tid for fixtures/first rollout:
     `fc6cceae-6ffd-432e-97cb-ad02ed2367f7`.

## Parent

`docs/issues/sso-mfa-federation/PRD.md`

## What to build

The payoff slice: when the IdP asserts MFA via `amr`, skip the app's own MFA challenge instead of double-prompting — but only when a per-tenant setting opts in, and always fail closed.

Builds on the enforcement gate (issue 01) and the `amr` plumbing (issue 02). Introduce a single decision point in the SSO callbacks:

```
mfaSatisfiedByIdp = trust_idp_mfa_enabled && in_array('mfa', $amr ?? [])
```

- When `mfaSatisfiedByIdp` is true → do **not** set `sso_mfa_pending`; the user proceeds without the app challenge.
- When false (setting off, `amr` absent, or `amr` present without `"mfa"`) → the issue-01 gate fires exactly as before. Absence is never treated as satisfaction.

Add a per-tenant setting (`trust_idp_mfa`, default **off**) via the existing settings mechanism so IdP-MFA trust is opt-in per instance. Flip the issue-02 logging from log-only to enforcing (the `amr` value now influences the gate). Optionally use `auth_time` for a freshness bound (reject stale assertions) — include if cheap, otherwise note as follow-up.

Precondition (from issue 02): the captured real logins must confirm the target tenant's IdP actually emits `amr:mfa` under its enforced policy before enabling `trust_idp_mfa` in production. Document that as an operator note on the setting.

## Acceptance criteria

- [ ] Per-tenant `trust_idp_mfa` setting (default off) surfaced through the existing settings UI/service
- [ ] With the setting on and `amr` containing `"mfa"`, an SSO login skips the app MFA challenge and proceeds
- [ ] With the setting on and `amr` absent or lacking `"mfa"`, the issue-01 gate still forces the app challenge (fail-closed)
- [ ] With the setting off, IdP `amr` is ignored and the issue-01 gate governs regardless of what the IdP asserted
- [ ] Consumer-Gmail behavior documented: `amr:mfa` may be absent even for 2SV users → falls through to app MFA (never admitted on absence)
- [ ] `auth_time` freshness bound applied, or explicitly deferred with a note
- [ ] Pest feature tests: setting-on + amr:mfa skips; setting-on + no amr challenges; setting-off ignores amr; recovery/edge cases from issue 01 still pass
- [ ] Operator note on the setting: only enable for tenants with admin-enforced MFA (Entra Conditional Access / Google Workspace), verified from captured logins

## Blocked by

- `docs/issues/sso-mfa-federation/issues/01-sso-mfa-enforcement-gate.md`
- `docs/issues/sso-mfa-federation/issues/02-broker-amr-plumbing.md`

## Comments

> *This was generated by AI during triage.*

## Agent brief (ready-for-agent 2026-07-24)

Implement the Azure-only skip. Concretely:

- Decision point (final form): `mfaSatisfiedByIdp = trust_idp_mfa && $provider === 'azure' && in_array('mfa', $amr ?? [], true) && $tid !== null && in_array($tid, $trusted_azure_tids, true) && $tid !== '9188040d-6c67-4c5b-b112-36a304b66dad'`. Empty `$trusted_azure_tids` ⇒ always false.
- Wire it into the SSO completion path (`SsoLoginService::completeLogin`, fed by `SsoCallbackController` which already has `amr`/`tid` in `$data['extra']`). When true, skip the `sso_mfa` pending stash and log in directly; when false, the issue-01 gate fires unchanged. Google/direct-Socialite callers pass no assertion ⇒ never satisfied.
- Settings: `trust_idp_mfa` (bool, default off) + `trusted_azure_tids` (list), via `SettingsService`, surfaced in the same settings UI as `require_mfa`. Operator note: only enable for a tenant with admin-enforced Entra MFA, and only after a real work/school login for that `tid` was captured with `amr:mfa`.
- Fail-closed tests are the crux (see acceptance criteria): setting-off ignores amr; amr absent/without mfa challenges; amr:mfa from an un-allowlisted tid challenges; amr:mfa from MSA tid challenges; empty allowlist challenges; only allowlisted-tid + amr:mfa skips. Capture `auth_time` into context but assert it does NOT gate (deferred).

## Triage Notes

Promoted to `ready-for-agent` 2026-07-24 (decisions locked above; 01+02 shipped, 02 validated).

**What we've established so far:**

- Category is `enhancement`.
- Blocked by issue 01 (the enforcement gate this slice skips) and issue 02 (the `amr` signal it reads). It cannot start until both land.
- The single decision point is `mfaSatisfiedByIdp = trust_idp_mfa_enabled && in_array('mfa', $amr)`, fail-closed on absence.

**Open decisions for a human before this goes `ready-for-agent`:**

- Default and scope of the `trust_idp_mfa` setting — confirmed off-by-default and per-tenant; confirm whether it should be gated to specific providers (e.g. Azure/Entra only initially) rather than any IdP.
- Whether to apply an `auth_time` freshness bound in this slice or defer it, and if applied, the max acceptable age.
- Which real captured logins from issue 02 are required as evidence before enabling `trust_idp_mfa` for a given tenant (Entra Conditional Access vs Google Workspace admin-enforced) — this is the manual precondition folded in from the former verification step.

Once issue 02's captured-login evidence exists and the above are decided, promote to `ready-for-agent` with an agent brief.

**Critical constraint surfaced during issue 02 (multi-tenant Azure):** the Entra app registration is configured for **"Any Entra ID tenant + personal Microsoft accounts" (`common`)**. That means *any* Microsoft user worldwide can obtain a validly-signed token whose `amr` reflects *their own* tenant's MFA policy — which we do not control. ID-token signature verification (added in issue 02) proves the token is **authentic**, not that the asserting tenant is **trusted**. So `mfaSatisfiedByIdp` for Azure must additionally be gated on an **allowlist of trusted tenant `tid`s**:

```
mfaSatisfiedByIdp = trust_idp_mfa_enabled
    && in_array('mfa', $amr ?? [])
    && (provider !== 'azure' || in_array($tid, $trusted_azure_tids))
```

Without the `tid` allowlist, an attacker can stand up their own Entra tenant, self-assert `amr:mfa`, and — if their token's email matches a PolyBag user — bypass the app's MFA. Issue 02 already **forwards `tid`** through the broker for exactly this.

**Confirmed during 2026-07-23 capture:** a real Microsoft SSO login returned `tid: 9188040d-6c67-4c5b-b112-36a304b66dad` — Microsoft's well-known **consumer (personal-account) tenant**. Personal MSA accounts emit **no `amr`** (and no `auth_time`) even after real MFA, so they can never satisfy IdP-MFA and must be hard-excluded from the allowlist. The broker's Azure app registration should also be narrowed from "any tenant + personal accounts" to **organizations only** so personal accounts can't reach the flow at all. `amr` for Azure must be validated against a real **work/school** account before this slice can rely on it.

**Entra setup precondition (confirmed 2026-07-23):** Entra v2.0 ID tokens omit `amr` and `auth_time` by default — both came back null for a real work/school account (`tid: fc6cceae-...`) that completed authenticator-app MFA, even though the token verified fine (`tid` forwarded). Per Microsoft's optional-claims reference, `amr` and `auth_time` must be added as **optional claims on the ID token** in the (shared broker) app registration; `amr` should use the `include_granular_amr` additional property. Once configured, authenticator MFA emits `amr: ["totp","mfa"]` (TOTP) or `["rsa","ngcmfa","mfa"]` (push) — `mfa` present only when MFA was completed, so `in_array('mfa', $amr)` is the uniform check for both providers. This config is a precondition before Azure `amr` can be captured/trusted. (A June 2026 Entra rollout auto-includes these on v2.0, but it's phased.)

New acceptance criteria for this slice:
- A per-instance list of trusted Azure `tid`s; an SSO login whose `tid` is not on it never satisfies MFA (falls through to the app challenge), even with `amr:mfa`.
- Test: `amr:mfa` from an untrusted `tid` still forces the app challenge.
- Alternatively/additionally, reconsider narrowing the Entra app registration to `organizations` or single-tenant to shrink this surface (infra decision, noted in issue 02).
