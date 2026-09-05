# Build the Amazon Buy Shipping adapter

Status: ready-for-agent

Repo: `polybag`

## Problem

The adapter itself: quote through `getRates`, purchase through `purchaseShipment`, void
through `cancelShipment`, track through `getTracking`.

Both reasons this was `needs-info` are now discharged. `01` established what is on offer —
three carriers eligible simultaneously against a 105-entry ineligible catalog — and
`postage-source-split/08` shipped the seam, so an adapter written now keeps Amazon's per-offer
`carrierName` instead of discarding it, which is the whole reason for the split.

What remains is ordinary sequencing, not missing information: `02` owns the offer and
observed-service stores this adapter reads and writes, so it stays blocked on `02` rather than
on an unanswered question.

## What to build

Notes that predate `01` and survived it unchanged:

- **Credentials** come from the shipment's own Amazon `DataSource`, not a `CarrierAccount` —
  the order lives in that seller's account, and per-client sources are how 3PL scoping already
  works. Amazon Shipping on non-Amazon orders is the opposite case and is out of scope here.
- **`directPurchaseShipment`** is the better fit for pre-selected rates, batch ship and
  auto-ship: one call, no token to expire.
- **Rate limits** are 80 rps / burst 100, so batch ship is not constrained.
- **Buying a label auto-confirms the Amazon order.** `AmazonSource::exportPackage()` calls
  `ConfirmShipment` for every Amazon package and Ship+ orders 400 on a manual confirm, so the
  export must skip it when the label came from Buy Shipping — gate on the stored Amazon
  `shipmentId`.
- **Order item IDs** for the request already exist: `PackageExportService::buildAmazonExportContext()`
  builds them from `packageItems.shipmentItem.source_item_id`. It is private and throws
  `PermanentExportException`; extract rather than duplicate.
- **Labels** are base64 with a format that must be one of the chosen rate's
  `supportedDocumentSpecifications` — the *purchase* fails otherwise, not the quote. PNG is not
  a format our printing path supports. Request 4x6 INCH explicitly.
- **Every rate this adapter returns must carry an `ObservedServiceIdentity`** on its
  `RateResponse`. That is what `07` gates automated purchase on, and it is the only thing that
  tells a discovered service apart from an authored `CarrierService` at selection time — a rate
  that arrives without one is treated as authored configuration and can win `selectBest()`
  unapproved. Build it from the same `(source, environment, carrierId, serviceId)` the adapter
  is already handing `ObservedServiceRecorder`, so the two cannot disagree.

## Acceptance criteria

- [ ] Rates appear alongside direct-carrier rates with the real carrier per offer
- [ ] Purchase, void and tracking work end to end against a real order
- [ ] The channel export does not double-confirm a Buy Shipping shipment
- [ ] Request bodies validate against the vendored Shipping v2 schema, following the pattern
      in `tests/Fixtures/Schemas/`
- [ ] An expired offer re-quotes rather than failing the packer
- [ ] Every returned rate carries its observed-service identity, so `07`'s approval gate applies

## Blocked by

- `02-specify-observation-and-offer-stores`
- `postage-source-split/08-split-carrier-adapter-interface`

## Comments

### 2026-09-04 — retriaged `needs-info` → `ready-for-agent`

The `needs-info` state was stale. It was set pending `01`, which returned on 2026-09-02 with a
GO, and pending the postage-source seam, which shipped as `postage-source-split/08`. Nothing
here is waiting on information any more — only on `02`, which is already recorded under
**Blocked by** and is the ordinary kind of dependency.

Left as `ready-for-agent` rather than started, so the blocker is expressed in one place.
