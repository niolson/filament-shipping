# Normalize the carrier of record and snapshot it at ship time

Status: done — PR #160

Repo: `polybag`

## Problem

ADR-0002 keeps `packages.carrier` as free text deliberately — Shopify may pick a carrier we
hold no row for and never will. But operational logic cannot depend on a source's spelling.
`USPS`, `US Postal Service`, and whatever Amazon returns in `carrierName` have to resolve to
one identity before `ShipDateService` or the channel export can act on them.

Without this, the split does not actually fix the exact-string consumers it exists to fix.
See ADR-0002 decision 5.

## What to build

An optional normalized carrier identity alongside the preserved raw value, **snapshotted onto
the package when it ships**.

Snapshotting is the point: normalization rules are editable, and recomputing on read would
let an alias edit retroactively rewrite what a past export or report meant.

Normalization must run *before* any carrier-policy lookup and cannot itself be built on the
name-keyed `CarrierRegistry` — that registry is the consumer of normalization, not its source.
Resolving to nothing is a valid outcome, not an error.

## Implementation update — 2026-09-02

PR #160 adds `carrier_aliases` and a nullable `packages.normalized_carrier_id`. Canonical
carrier names and editable aliases resolve through `CarrierNormalizer`, independently of
`CarrierRegistry`; the normalized ID is written in the same optimistic-lock update as the
raw carrier value in `Package::markShipped()`.

Shopify now records the physical carrier it reports rather than `Shopify` as the carrier of
record. If Shopify omits the tracking company for an explicitly requested carrier, the
requested carrier code is retained as an uppercase fallback; an automatic purchase with no
reported carrier remains the valid unmapped state. Shopify-specific UI, filtering and void
synchronization key from postage-source provenance rather than carrier text.

Voiding clears the snapshot so a later shipping transition can normalize again. Alias edits
validate in both the Filament relation manager and the model, and carrier rows referenced by
shipped packages cannot be deleted. Existing shipped packages are deliberately not backfilled:
this slice promises a ship-time snapshot, while issues `04` and `05` own the first consumers
of that snapshot.

## Acceptance criteria

- [x] Raw carrier string is preserved unchanged on every package
- [x] A normalized identity is written at ship time and never recomputed on read
- [x] Editing a normalization rule does not change any already-shipped package
- [x] An unrecognized carrier normalizes to null and the package still ships
- [x] Normalization does not call `CarrierRegistry`

## Blocked by

- `01-record-postage-source-provenance` — merged 2026-09-02 (PR #155)
