# Document how to run PolyBag without our hosted services

Status: done
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

- [x] A self-hosting doc exists covering: broker-free operation, which developer accounts to register per integration, and the exact `.env` keys for each
- [x] `.env.example` no longer implies `connect.polybag.app` or `updates.polybag.app` are available to the reader
- [x] The `OAUTH_BROKER_*` UI tooltips either account for the broker-free case or point at the new doc
- [x] Address validation documents the direct-key path as valid for production self-hosting
- [x] Demo tooling is either documented or explicitly marked internal
- [x] A reader can get to a working FedEx or UPS rate quote using only their own credentials and this doc

## Blocked by

None - can start immediately. Overlaps `issues/03-de-tenant-deployment-surface.md` on
`.env.example`; whichever lands second reconciles.

## Completion notes

**`docs/self-hosting.md` — the new document**

Structured around the thing a reader actually needs: a table of every hosted-only
reference they will trip over and what replaces it, then per-integration setup for USPS,
FedEx, UPS, Shopify, Amazon SP-API, Google SSO, Entra SSO, Google Address Validation, and
mail. Each section names the developer account to register, the exact fields in the app
(carrier and import credentials live on `CarrierAccount` / `DataSource` records, not
`.env`), and any non-obvious trap.

The last acceptance criterion — a reader reaching a rate quote — is a numbered
walkthrough: company address, carrier account with your own credentials, `/manual-ship`,
pack, rate. It points at `FAKE_CARRIERS=true` for anyone who wants to exercise the UI
before registering anything.

**On the FedEx registration wizard.** `FedexRegistrationService::getConnector()` already
falls back to calling FedEx directly when `OAUTH_BROKER_URL` is empty, so the wizard is
not *technically* broker-locked. But the flow provisions child credentials under a parent
CSP key, which needs a FedEx Compatible Solution Provider arrangement. The doc says to
skip the wizard and use a plain developer app plus an account number, which is what a
self-hoster can actually get. Same for the direct child-token path in
`FedexConnector::getDirectChildAccessToken()` — real, but it presumes a CSP parent.

**`OAUTH_BYPASS_BROKER` is a hosted flag, not a self-host one.** Both SSO controllers
gate on `filled(broker_url) && ! bypass_broker`, so with no broker URL, SSO already goes
direct through Socialite. The doc and `.env.example` now say the flag only matters when a
broker *is* configured, rather than presenting it as the self-hoster's escape hatch.

**UI wording — `OAuthService::brokerlessGuidance()`**

The four disabled-action tooltips said "Set `OAUTH_BROKER_URL`, `OAUTH_BROKER_SECRET`,
and `OAUTH_INSTANCE_ID` in .env", which is advice a self-hoster cannot act on. They now
call a single `OAuthService` method that returns null when a broker is configured and
otherwise names the direct alternative for that specific integration — developer app
credentials under Advanced / API App Credentials for USPS and UPS, per-source credentials
for Shopify and Amazon — plus a pointer to the doc.

Put in the service rather than duplicated across the two page classes: same rule, four
call sites, and it made the behaviour unit-testable without reaching through Livewire.

Also swept the adjacent copy, since a tooltip fix is worth little if the field beside it
still says the same thing:

- `CarrierAccountForm` section descriptions no longer say "use the shared
  polybag-connect credentials" / "most installations leave these empty"; they now
  distinguish hosted from self-hosted.
- `DataSourceForm` helper texts and the "Uses tenant-level credentials" placeholders —
  there are no tenant-level credentials on a self-hosted install.
- The four `RuntimeException` messages in `OAuthService` gave the same unactionable env
  advice. Now: "This flow is hosted-only; self-hosted installs enter credentials
  directly."
- `config/services.php` and `GoogleAddressValidationConnector` described the direct
  Google key as a local-dev fallback. It is the production path when there is no broker.

**`.env.example`**

No `polybag.app` hostname remains. The broker block leads with "hosted PolyBag only.
Leave these three empty" and states plainly that empty is supported. Mail drops the
Resend domain for a neutral example and notes that MFA codes ride on it. The address
validation key is described as the self-hosted production path.

`scripts/security-scan-host.sh` still defaults to `noreply@updates.polybag.app`. Left
alone deliberately — it is a Tier 1 ops file that leaves with issue 04, and it already
honours `SECURITY_SCAN_EMAIL_FROM`.

**Demo tooling — marked internal**

`demo:reset` is not opaque (it re-seeds from whichever active Database data source it is
given, so there is no secret schema), but its audience is our sales demo and its
fill-to-now wrapper is in a private repo. Its `$description` is now prefixed
`[Internal]`, a class docblock says so, and `docs/self-hosting.md` lists it under things
a self-hoster can ignore.

**Tests**

`tests/Feature/BrokerlessGuidanceTest.php` — seven tests covering the guidance string
(names the alternative, never mentions `OAUTH_BROKER_SECRET`), that it disappears when a
broker is configured, that the four connect actions are disabled without one and enabled
with one, that the broker exception no longer gives env advice, and that `.env.example`
carries neither hosted hostname. Full suite green: 1591 passed. PHPStan and Pint clean.

**Overlap with issue 03**

Issue 03 also touches `.env.example` — for the `infra/docker-compose.yml` reference and
the installer's `polybag.app` defaults, neither of which this issue changed. The mail
line it flagged is done here, as it said it would be.
