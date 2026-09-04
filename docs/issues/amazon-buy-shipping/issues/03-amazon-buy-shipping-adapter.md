# Build the Amazon Buy Shipping adapter

Status: needs-info

Repo: `polybag`

## Problem

The adapter itself: quote through `getRates`, purchase through `purchaseShipment`, void
through `cancelShipment`, track through `getTracking`.

`needs-info` until `01` establishes what is actually on offer, and it should not start before
the postage-source seam exists — building it against the old model means writing an adapter
that discards Amazon's per-offer `carrierName`, which is the whole reason for the split.

## What to build

Notes that will not change whatever `01` returns:

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

## Acceptance criteria

- [ ] Rates appear alongside direct-carrier rates with the real carrier per offer
- [ ] Purchase, void and tracking work end to end against a real order
- [ ] The channel export does not double-confirm a Buy Shipping shipment
- [ ] Request bodies validate against the vendored Shipping v2 schema, following the pattern
      in `tests/Fixtures/Schemas/`
- [ ] An expired offer re-quotes rather than failing the packer

## Blocked by

- `02-specify-observation-and-offer-stores`
- `postage-source-split/08-split-carrier-adapter-interface`
