# Export the carrier of record to sales channels

Status: done — PR #161

Repo: `polybag`

## Problem

`PackageExportService::buildExportData()` passes `$package->carrier` straight to the
destination. `AmazonSource::CARRIER_MAP` has no `Shopify` key, so a Shopify-bought label on
an Amazon order confirms as `carrierCode: "Other", carrierName: "Shopify"` while carrying a
USPS tracking number — and the buyer gets a tracking link that does not resolve.

This is defect 1 of the three in ADR-0002's context.

## What to build

Export the **normalized carrier of record** rather than the raw dispatch value.

## Implementation update — 2026-09-02

PR #161 adds `Package::carrierOfRecordName()` — the canonical name of the identity `03`
snapshotted at ship time, falling back to the raw carrier value when normalization resolved
to nothing. `PackageExportService::buildExportData()` sources the `carrier` export key from
it, and `normalizedCarrier` is eager-loaded on both the single-package and sweep paths so the
change costs no extra query per package.

Both destinations read that one key, so Amazon's `carrierCode` and Shopify's
`trackingInfo.company` are corrected together; no destination driver changed. An unmapped
carrier still reaches Amazon as `Other` plus its own name, which is what `AmazonSource`
already did with a raw value it could not map.

Packages shipped before `03` carry no snapshot and fall back to their raw carrier. They export
sanely, without alias normalization.

## Correction — 2026-09-04

The paragraph above originally credited that fallback to the `02` backfill "having already
rewritten `carrier` from `metadata.shopify_tracking_company`". **No such rewrite exists.**
`02`'s own update section replaced that branch with a guard, and
`2026_09_02_193821_backfill_package_postage_source` *throws* on any row matching
`carrier = 'Shopify'` or `metadata like '%shopify_shipping_label_id%'` rather than guessing at
it.

The fallback is safe for a different and stronger reason: a pre-`03` row whose raw carrier
reads `Shopify` cannot exist, because the backfill refuses to run at all if one does. Worth
correcting rather than deleting, because the wrong version described a rewrite that would have
been the more fragile design — inferring a carrier of record from metadata is exactly what `02`
decided not to do.

## Acceptance criteria

- [x] A Shopify-bought USPS package exports to Amazon as `carrierCode: "USPS"`
- [x] A carrier that normalizes to nothing still exports safely as `Other` + its raw name
- [x] `AmazonImportExportTest` and `PackageExportTest` pass; a case is added for the
      Shopify-bought-on-Amazon-order path
- [x] The Shopify export destination receives the same corrected value

## Blocked by

- `03-normalize-carrier-of-record` — merged 2026-09-02 (PR #160)
