# FBA orders should not import by default, and must be marked when they do

Status: ready-for-agent

## Problem

`AmazonSource::fetchShipments()` filters orders by `fulfillmentStatuses` only — never by who fulfills them. Amazon returns both merchant-fulfilled (MFN) and Amazon-fulfilled (FBA) orders, and the import writes both into `shipments` identically.

FBA orders are picked, packed, and shipped by Amazon. They must never appear in the packing queue: a warehouse operator has no way to tell them apart today, and packing one produces a duplicate physical shipment plus a `confirmShipment` export attempt that Amazon will reject.

Evidence from the first production historical import (1006 orders, data source 8): **24 orders came back with `fulfillment.fulfilledBy = AMAZON`** and were imported as ordinary shipments.

The order-level fulfillment block already carries the discriminator, and `mapOrderToShipment()` already stores it as `metadata.amazon_fulfilled_by` (`AMAZON` | `MERCHANT`). Nothing acts on it.

## Expected behavior

1. **Exclude by default.** Skip orders with `fulfillment.fulfilledBy === 'AMAZON'` during mapping. Amazon's `SearchOrders` may also support server-side filtering — check the v2026-01-01 model for a `fulfillmentChannels`-style query parameter before filtering client-side, since skipping server-side is cheaper and keeps `maxResultsPerPage` counts honest for historical imports (`_historical_max_orders` currently counts orders we may then discard).
2. **Make it opt-in.** Add an `settings.import_fba_orders` toggle to the Amazon Import Settings section of `DataSourceForm`, default off.
3. **Mark them clearly when imported.** If the toggle is on, FBA shipments need to be obvious in the UI — at minimum a badge on the Shipments table/view driven by `metadata.amazon_fulfilled_by`, and they should be kept out of the pack/ship flows (or blocked with an explanatory message at `/pack/{shipment_id}`).
4. **Don't export them.** `exportPackage()` should refuse an FBA order with a `PermanentExportException` rather than letting Amazon reject the `confirmShipment` call.

Open question for the maintainer: what should happen to the 24 FBA orders already imported — leave them (they are historical/shipped, so harmless), or purge them? A one-off cleanup could reuse the `metadata->amazon_fulfilled_by` filter.

## Test notes

`tests/Feature/AmazonImportExportTest.php` — `sampleAmazonOrder()` now carries a v2026-01-01 `fulfillment` block, so a test can flip `fulfilledBy` to `AMAZON` and assert no shipment is created by default, and one is created (marked) when the toggle is on. `DataSourceResourceTest.php` covers the form field. Export refusal fits alongside the existing `PermanentExportException` cases in the same file.

## Comments

**2026-08-13 (Claude):** Filed while wiring `fulfillment.fulfillmentServiceLevel` into shipping method mapping. That change also fixed `metadata.amazon_order_status`, which had been reading the v0 `orderStatus` key and was null on all 1006 imported rows; `fulfilledBy` was mapped correctly and is reliable.
