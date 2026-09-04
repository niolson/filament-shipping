# Resolve the Amazon sandbox host per API, not globally

Status: ready-for-agent

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

## What to build

Per-API sandbox host resolution on the connector. Production is unaffected — `base_url` is
already NA and correct for both.

Shape is open, but the constraint is that a request must be able to state which sandbox host
it needs without every caller having to know. Keep the reasoning that is currently in the
`config/services.php` comment; it is the only place the JP/FE dependency is written down,
and it is not discoverable from the code.

## Acceptance criteria

- [ ] Orders v2026 sandbox calls reach the FE host and the existing import sandbox path works
      again
- [ ] Shipping v2 sandbox calls reach the NA host
- [ ] Production resolution is unchanged for both
- [ ] The JP-marketplace/FE-host reasoning survives somewhere a reader will find it
- [ ] A test covers both hosts resolving from the same connector

## Blocked by

None — can start immediately. Does not block `01`, whose production run uses `base_url`, but
does block any further Amazon **sandbox** work and currently leaves Amazon imports untestable
in sandbox.
