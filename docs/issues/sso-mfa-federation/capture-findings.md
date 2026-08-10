# SSO-MFA federation — real-login capture findings

Status: reference
Date: 2026-07-23

Empirical results from capturing real Google and Microsoft SSO logins through the
deployed broker (`connect.polybag.app`, running merged PR #2) into the local
`dev.polybag.app` tenant. This is the evidence the "trust IdP MFA" slice
(`issues/03-trust-idp-mfa-skip.md`) was waiting on. Log line format (from the
diagnostic logging in `SsoCallbackController`):

```
SSO login MFA assertion for {provider} {email, amr, auth_time, tid, extra_keys}
```

## Proven working (broker plumbing + verification)

- ID-token **verification passes against real tokens** — `tid` flowing through for
  Azure and `amr`/`auth_time` for Google both confirm the token verified
  (signature/JWKS, `aud`, signing-key issuer) and the claims pass through the
  broker's `extra` untouched. Broker code needs no further change.
- `extra_keys` reflects exactly which claims the IdP returned, so absence is
  visible rather than silent.

## Google

- **Plumbing works.** A password login returned `amr: ["pwd"]` + `auth_time`,
  forwarded end-to-end.
- **`amr:["mfa"]` not yet captured.** Google **skips 2-Step Verification on
  trusted sessions/devices**, so real logins used only password → `amr:["pwd"]`.
  `amr` honestly reflects the methods used *in that sign-in event*; enabling SMS
  2SV does not make every login re-run it.
- **Blocker for the mfa capture:** an intermittent **500 on Google's own server**
  (`accounts.google.com`) after entering the SMS code in incognito. **Not** caused
  by our request — softening the `claims` param from `essential:true` to voluntary
  (`{"id_token":{"amr":null,"auth_time":null}}`, tested live on the server then
  reverted) did **not** fix it. Appeared right after enabling 2SV on the account;
  presumed a Google-side quirk / account still settling. **Retry later.**
- **How to actually get `amr:mfa` from Google:** sign in from a genuinely
  untrusted context (revoke trusted devices first), or — the production-correct
  path — enforce 2SV at the **Google Workspace admin** level so Google challenges
  every time. Consumer "remember this device" 2SV is the weakest case.

## Azure / Entra

- **Root cause of initial `null`:** Entra **v2.0 ID tokens omit `amr` and
  `auth_time` by default** — both were null for personal *and* work accounts that
  had completed MFA, even though the token verified (`tid` forwarded). This is the
  documented v2.0 behavior (these were v1.0-default claims). Resolves the
  "conflicting information about Entra amr" question: Entra **does** support `amr`
  on v2.0, but it must be **requested via optional claims**.
- **Fix applied — and it works.** After adding `amr` (+ `include_granular_amr`) and
  `auth_time` as **ID-token optional claims** on the (shared broker) app
  registration, a real **work/school** account emitted:
  ```
  amr: ["pwd","totp","mfa"], auth_time: 1784837999, tid: fc6cceae-6ffd-432e-97cb-ad02ed2367f7
  ```
  `mfa` present. This is a portal config change, **no broker code change**.
- **`amr:mfa` is reported on silent SSO** when the session already satisfied MFA —
  i.e. Entra asserts the session's cumulative auth methods without re-prompting.
  This is the *desirable* "session is MFA-satisfied" semantic (better than Google's
  per-event behavior). `auth_time` is available for an optional freshness bound.
- **Personal Microsoft accounts are a dead end.** MSA accounts
  (`tid: 9188040d-6c67-4c5b-b112-36a304b66dad`, Microsoft's consumer tenant)
  returned `amr:null` in every case — silent SSO, forced MFA, and incognito. They
  structurally do not emit `amr`, optional-claims config notwithstanding.

## Conclusions / decisions

1. **The broker (issue 02) is validated end-to-end.** Verification + forwarding
   work against real tokens for both providers.
2. **Entra optional-claims config is a required setup step** (add `amr` +
   `auth_time` to the ID token on the broker app registration). Documented as a
   precondition on issue 03.
3. **Narrow the Azure app registration to "Multiple Entra ID tenants"
   (organizations only).** Personal accounts can't produce `amr` and only widen the
   trust surface. The shared broker still needs multi-org.
4. **`in_array('mfa', $amr)` is the uniform MFA check** for both providers.
5. **Issue 03 `tid` allowlist — concrete values:** trust
   `fc6cceae-6ffd-432e-97cb-ad02ed2367f7` (the test org tenant); permanently
   exclude `9188040d-6c67-4c5b-b112-36a304b66dad` (MSA consumer tenant).

## Remaining before issue 03 can enable Azure/Google trust

- [ ] Capture a Google `amr:["…mfa…"]` login (blocked on the Google 500 / trusted-
      session behavior — retry later, or use Workspace-enforced 2SV).
- [ ] Narrow the Entra app registration to organizations-only.
- [ ] Decide `trust_idp_mfa` default/provider scope + optional `auth_time`
      freshness bound (open decisions on issue 03).

## Reference: Entra OIDC AMR values (from Microsoft optional-claims reference)

| Method | `amr` values |
| --- | --- |
| Password | `pwd` |
| Authenticator push | `rsa`, `ngcmfa`, `mfa` |
| Authenticator TOTP | `totp`, `mfa` |
| SMS | `sms`, `mfa` |
| Phone call | `tel`, `mfa` |
| FIDO2 / passkey | `fido`, `mfa` |
| Windows Hello for Business | `hwk`, `mfa`, `ngcmfa` |

`mfa` (and `multipleauthn`) are emitted **only when the user actually completed
MFA**. Source: learn.microsoft.com/en-us/entra/identity-platform/optional-claims-reference
