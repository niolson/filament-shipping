# Document how to run PolyBag without our hosted services

Status: ready-for-agent
Category: documentation
Type: AFK

## Parent

`docs/issues/oss-repo-split/PRD.md`

## What to build

Several app features route through infrastructure we operate. The code already has
escape hatches; what is missing is any document telling a self-hoster they exist and
that the hosted path is not available to them. Without this, the predictable outcome is
issues asking us to issue an `OAUTH_BROKER_SECRET`.

### The OAuth broker

`.env.example:127` reads `# OAuth Broker (connect.polybag.app)` / "Handles OAuth
authorization code flow for all instances" — which reads like a service anyone can
point at. It is ours, and it holds *our* carrier and IdP developer credentials.

Affected paths: FedEx and UPS carrier account OAuth, Google and Azure SSO, Amazon
SP-API, and Google Address Validation (proxied through the broker in production).
`OAUTH_BYPASS_BROKER=true` is the documented escape for SSO, and
`CarrierAccountForm.php:130` notes custom FedEx developer credentials fall back to
"the shared polybag-connect credentials".

Document, per integration: what a self-hoster must register themselves (FedEx, UPS,
Amazon SP-API, Google Cloud, Entra), which `.env` keys to set, and that leaving the
broker unset is a supported configuration rather than a broken one. Check the UI
tooltips too — `EditCarrierAccount.php:44` and `EditDataSource.php:130` tell the user
to "Set `OAUTH_BROKER_URL`, `OAUTH_BROKER_SECRET`, and `OAUTH_INSTANCE_ID`", advice a
self-hoster cannot act on.

### Mail

`.env.example:90` and the security-scan scripts default to
`noreply@updates.polybag.app`, our Resend domain. Replace with a neutral example and
explain that any Laravel mailer works; Resend is our choice, not a requirement. MFA
login codes go through this, so it is not optional plumbing for anyone enabling MFA.

### Demo tooling

`app/Console/Commands/DemoReset.php` and `app/Filament/Widgets/DemoModeWidget.php`
depend on a demo import database whose tooling lives in the private
`polybag-demo-data-tools` repo. `demo:reset` is already gated to `APP_ENV` of `demo`
or `local`. Either document it as internal-only, or note what shape of import database
it expects so the command is not just a dead end for a reader.

### Address validation

`GOOGLE_ADDRESS_VALIDATION_API_KEY` is described in `.env.example` as "local dev only
... in production this is proxied through the broker". For a self-hoster there is no
broker, so the direct key is their production path. Say so.

## Acceptance criteria

- [ ] A self-hosting doc exists covering: broker-free operation, which developer accounts to register per integration, and the exact `.env` keys for each
- [ ] `.env.example` no longer implies `connect.polybag.app` or `updates.polybag.app` are available to the reader
- [ ] The `OAUTH_BROKER_*` UI tooltips either account for the broker-free case or point at the new doc
- [ ] Address validation documents the direct-key path as valid for production self-hosting
- [ ] Demo tooling is either documented or explicitly marked internal
- [ ] A reader can get to a working FedEx or UPS rate quote using only their own credentials and this doc

## Blocked by

None - can start immediately. Overlaps `issues/03-de-tenant-deployment-surface.md` on
`.env.example`; whichever lands second reconciles.
