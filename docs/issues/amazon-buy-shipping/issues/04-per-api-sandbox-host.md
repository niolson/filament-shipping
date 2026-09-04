# Resolve the Amazon sandbox host per API, not globally

Status: done

Repo: `polybag`

## Problem

`AmazonSpApiConnector::resolveBaseUrl()` returns one sandbox host for every Amazon API, and
the two APIs we use disagree about which host they need:

- **Orders v2026-01-01** — the only working sandbox test case uses Amazon's JP marketplace
  (`A1VC38T7YXB528`, see `AmazonSource::fetchShipments()`), which resolves only against the
  **FE** host. The NA host 403s for it.
- **Shipping v2** — sandbox cases are **NA**. The FE host is wrong for a US marketplace.

`config/services.php` currently carries a comment explaining the FE choice, which was
correct while Orders was the only sandbox consumer. Adding Shipping v2 makes a single global
value unsatisfiable: whichever host is set, one of the two APIs is broken in sandbox.

Right now `sandbox_url` has been flipped to NA in a working tree to run the `01` probe,
which means **Amazon import sandbox testing is broken until this lands**. That change should
not be committed on its own.

## Implementation update — 2026-09-04

The host is now resolved per request rather than per connector. A request that needs a
sandbox host other than the connector's default implements
`App\Http\Integrations\Amazon\DeclaresSandboxRegion` and names an `AmazonSpApiRegion`;
`AmazonSpApiConnector::boot()` moves it there, and only while `sandbox_mode` is on.
Production resolves through `resolveBaseUrl()` exactly as before.

`SearchOrders` declares FE. `ConfirmShipment` and `GetShippingRates` declare NA — the
latter because Shipping v2 is the reason the default cannot be FE, the former because the
FE import makes the opposite conclusion easy to draw. `SearchCatalogItems` needs nothing:
it only runs on the historical import path, which refuses to start in sandbox.

The import and the export do **not** pair up in sandbox, which is the trap here.
`AmazonSource::exportPackage()` discards the imported order under `sandbox_mode` and posts
Amazon's own confirmShipment test case, whose pattern-matched values are a US order ID and
`ATVPDKIKX0DER`. Reasoning from "it confirms the order the import returned" gives FE and is
wrong; the two sandbox paths are unrelated fixtures.

`services.amazon.sandbox_url` is now NA and is documented as the default for requests that
declare no region, which unblocks the `01` working tree. The JP-marketplace/FE reasoning
moved out of `config/services.php` and into the `DeclaresSandboxRegion` docblock, next to
the contract that acts on it; the sandbox branch in `AmazonSource::fetchShipments()` still
carries its half and now points at `SearchOrders` rather than at the config value.

## What to build

Per-API sandbox host resolution on the connector. Production is unaffected — `base_url` is
already NA and correct for both.

Shape is open, but the constraint is that a request must be able to state which sandbox host
it needs without every caller having to know. Keep the reasoning that is currently in the
`config/services.php` comment; it is the only place the JP/FE dependency is written down,
and it is not discoverable from the code.

## Acceptance criteria

- [x] Orders v2026 sandbox calls reach the FE host and the existing import sandbox path works
      again
- [x] Shipping v2 sandbox calls reach the NA host
- [x] Production resolution is unchanged for both
- [x] The JP-marketplace/FE-host reasoning survives somewhere a reader will find it
- [x] A test covers both hosts resolving from the same connector

## Blocked by

None — can start immediately. Does not block `01`, whose production run uses `base_url`, but
does block any further Amazon **sandbox** work and currently leaves Amazon imports untestable
in sandbox.

## Comments

### 2026-09-04 — shipped

`tests/Unit/Integrations/AmazonSpApiConnectorTest.php` covers both hosts resolving from one
connector, production staying NA for both APIs, a region-less request staying on the default,
and query parameters surviving the host swap. `AmazonImportExportTest` covers the two
real paths end to end: the sandbox export reaching NA and the sandbox import reaching FE.
Full suite green.

`ConfirmShipment` was first written as FE on the reasoning that it confirms an order
`SearchOrders` fetched. Code review caught it before it shipped. The two sandbox paths do
not pair up — see the implementation note above — and the fix is pinned by a test that
asserts the URL `exportPackage()` actually sends, rather than the region the request
declares, so the same wrong inference cannot pass again.

One thing worth knowing for `03`: the swap happens in `boot()`, after Saloon has already
joined the base URL to the endpoint, because `resolveBaseUrl()` is called without request
context and there is no earlier hook that has both. `resolveBaseUrl()` therefore is not the
whole answer any more, and says so. This follows the existing `boot()`-and-`setUrl()` pattern
in `FedexRegistrationProxyConnector` and `GoogleAddressValidationProxyConnector`.
