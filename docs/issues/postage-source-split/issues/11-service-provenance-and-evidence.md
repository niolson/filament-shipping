# Record service provenance alongside the service value

Status: done — PR #170

Repo: `polybag`

## Problem

A single `packages.service` column cannot express how much we actually know. ADR-0003
decision 7 separates the requested preference — which is audit metadata and coexists with any
outcome — from the service value and its evidence.

Without this, an inferred service is indistinguishable from a confirmed one, and the channel
export would pass a guess to a marketplace as fact.

## What to build

| Field | Meaning |
|---|---|
| requested preference | what we asked the source for — audit metadata, never the service value |
| service value | the actual service, or null |
| evidence | `confirmed` (the source reported it), `inferred` (derived), or `unknown` |
| inference method + ruleset version | recorded whenever evidence is `inferred` |

**Channel exports publish `confirmed` only.** `AmazonSource::exportPackage()` already omits
`shippingMethod` when blank, so an inferred service is omitted rather than published — a guess
sent to a marketplace becomes a buyer-facing fact we cannot retract, and omitting a field costs
nothing.

Inference itself is out of scope here; this is the model that makes it safe to add later.

## Acceptance criteria

- [x] Evidence is recorded with every service value, including `unknown`
- [x] A requested preference can be stored on a package whose evidence is `unknown`
- [x] The channel export publishes only `confirmed` services and omits the rest
- [x] Existing packages migrate to `confirmed` where a service is set, `unknown` where not —
      **except Shopify rows**, whose service value was only ever the preference we asked for
      (see the exception below)
- [x] `PackageExportTest` covers the omission

## Exception to the migration criterion — added 2026-09-03, during implementation

Certifying *every* existing non-null service as `confirmed` would contradict ADR-0003
decision 5, which holds that a Shopify-bought service is permanently unknown. Such rows can
exist: `ShopifyAdapter::createShipment()` has written an unconfirmed value into
`packages.service` for as long as the adapter has existed, and it is the preference we sent,
not a fact Shopify reported back.

The backfill therefore moves those values to `requested_service` and leaves the evidence
`unknown`, before applying the criterion above to everything else. This keeps the invariant
`markShipped()` enforces on new rows — `unknown` never carries a service value — true across
the whole table, which a literal reading of the criterion would break on its first row.

The demotion is scoped to sources whose `source_type` is `ShopifySource`, not to
`postage_source = 'postage_data_source'` in general: Amazon Buy Shipping is also bought
through a data source and does report the service it sold. No such row can exist when the
migration runs, since Buy Shipping postdates it, but the predicate should not depend on that.

## Correction to the history above — 2026-09-04

The exception originally said `createShipment()` had written
`$request->selectedRate->serviceName` into `packages.service` "since the adapter was added".
That is one era of two, and the earlier one is the opposite mistake:

| From | `service` was written as |
|---|---|
| `daf1bd8` (adapter added) | `$label->trackingCompany ?? $request->selectedRate->serviceName` |
| `12da2e5` (PR #160, slice `03`) | `$request->selectedRate->serviceName` |
| `c2938c0` (PR #170, this slice) | `null`, with the preference in `requested_service` |

So before `03` the column primarily held a **carrier name**, which is what ADR-0003's context
and slice `02`'s note both describe, and what `shopify-shipping-carrier/10` records correctly.
The demotion this slice performs would move such a value into `requested_service` and label a
carrier name as the service we asked for.

That window is empty, and provably so rather than incidentally: **no Shopify Shipping label
has ever been bought in production or in development**, across the whole run of these slices —
the same fact slices `01` and `02` are built on. Independently, `02`'s guard would have thrown
on any pre-`01` row of that shape before this migration ever ran. Both migrations were no-ops
on real data.

The claim is corrected rather than the migration, because the predicate is right for the
reachable era and the unreachable one has no rows to be wrong about. What was wrong was the
justification: it asserted a single history where there were two, and the honest version is
that the demotion is safe because the table is empty, not because every historical value was a
service preference.

## Blocked by

- `01-record-postage-source-provenance`
