# Stop writing `trackingCompany` into `packages.service`

Status: done — landed with `postage-source-split/11`

Repo: `polybag`

## Problem

`ShopifyAdapter::createShipment()` sets `service: $label->trackingCompany ?? ...`. That is a
**carrier** name in the service column — "USPS" stored as though it were a service. It is the
mirror image of the carrier column holding "Shopify", and it is why the backfill in
`postage-source-split/02` has to read `metadata.shopify_tracking_company` and treat `service`
only as a fallback.

ADR-0003 decision 5 and action item 7.

## Update — 2026-09-03, on implementing `postage-source-split/11`

The problem statement above was half stale by the time this was picked up. The carrier half
had already been fixed by an earlier slice: `createShipment()` read
`carrier: $label->trackingCompany ?? Str::upper($carrierCode)`, which is correct. What
remained in the service column was not `trackingCompany` but
`service: $request->selectedRate->serviceName` — the preference we *asked* Shopify for,
recorded as though it were what came back. The same defect, one revision later: a value that
was never confirmed sitting in the confirmed position.

That is what landed. `ShopifyAdapter::createShipment()` now records `service: null`,
`serviceEvidence: Unknown`, and the selection as `requestedService` — null for the `auto`
code, since asking for nothing is not a preference. The packages table shows the requested
value as a description under the now-blank service column, so the gap reads as a fact rather
than as missing data.

Two consequences worth carrying forward:

- The backfill in `postage-source-split/02` reads `metadata.shopify_tracking_company` and
  treats `service` as a fallback. That fallback is now dead weight for new rows, though it
  still describes historical ones.
- `02-establish-preferred-rate-selection-service-codes` proposes answering "is
  `preferredRateSelection` honoured?" with a query for packages where the requested and
  actual values disagree. The columns moved: the requested side is now `requested_service`
  (with the raw code still in `metadata.shopify_requested_service_code`), and the only thing
  it can be compared against is `carrier`, since Shopify reports no service to disagree
  about. That issue's code snippet is stale in the same way this one's was.

Determining the service after the fact is `11-infer-the-service-from-the-label`.

## What to build

Write the carrier of record where the carrier belongs and leave the service **unknown**, since
Shopify never reports one. See `postage-source-split/11` for the provenance model that lets a
service be legitimately absent rather than guessed.

## Acceptance criteria

- [x] A Shopify purchase records the carrier from `trackingInfo.company` as the carrier
      (already true before this issue was picked up)
- [x] `packages.service` is left null rather than filled with a carrier name
- [x] Nothing downstream assumes `service` is always populated — the packages table and
      the label batch item already render a null service, and the channel export now gates
      on evidence rather than on the value being present
- [x] `ShopifyAdapterTest` asserts the service is not set

## Blocked by

- `postage-source-split/11-service-provenance-and-evidence`
