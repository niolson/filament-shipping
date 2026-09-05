# Withdraw the Shopify offer once a shipment has a shipped package

Status: done — 2026-09-05

Repo: `polybag`

## Problem

One Shopify fulfillment order buys **one** label, and `shopify_fulfillment_order_id` is
stored on the **shipment**, not the package — `ShopifyShippingLabelService::fulfillmentOrderId()`
reads `$package->shipment?->metadata['shopify_fulfillment_order_id']`. Every package of a
shipment therefore points at the same fulfillment order, and a second purchase asks Shopify
to fulfill something it has already fulfilled.

What comes back is untested. The expected failure is `JOB_NOT_ENQUEUED` ("There is another
shipping label being purchased for this order") or `FULFILLMENT_ORDER_INVALID`, both of which
`ShopifyAdapter::createShipment()` surfaces verbatim to the packer through
`ShipResponse::failure()`. That is a carrier-internal string arriving at a packing bench
after the box is taped shut.

The blast radius is smaller than it first looks — the offer only appears for a client with
`blind_purchase_enabled`, on a shipment imported from an active Shopify data source, and
never in auto-ship, batch ship, shipping rules or `RateSelector` (ADR-0003 decision 6). It
takes a person choosing Shopify a second time on the Ship page. But nothing stops them.

## What to build

Add a fourth gate to `ShopifyAdapter::blindPurchaseOffers()`, alongside the three already
there: no offer when the package's shipment already has another package with status
`PackageStatus::Shipped`.

```php
if ($package->shipment->packages()
    ->whereKeyNot($package->id)
    ->where('status', PackageStatus::Shipped)
    ->exists()) {
    return collect();
}
```

`Void` is deliberately not included. Voiding a Shopify label reopens the fulfillment order
(`ShopifyShippingLabelService` already tracks `LABEL_VOIDED` and `CANCELLED` states), so a
shipment whose only previous label was voided must still be able to buy another one.

This holds at purchase time as well as in the list, because
`EloquentPackageShippingWorkflow::resolveBlindOffer()` re-derives the offers through
`ShippingRateService::blindPurchaseOffersFor()` and matches by identity rather than trusting
the incoming one. A stale Ship page therefore refuses with "Offer No Longer Available"
instead of reaching Shopify.

The packer is not told *why* the offer is gone. That is consistent with the three existing
gates — the adapter's docblock says none of them is an error worth raising — but it is the
weakest part of this fix. Saying more would mean giving `BlindPurchaseSource` a way to report
an exclusion reason: `ShippingRateService::$exclusions` is private and keyed by carrier name,
written only from the rate path, and duplicating the rule outside the adapter is exactly what
`blindPurchaseOffersFor()`'s docblock warns against ("rather than under a second copy of the
rules that can drift from the first"). Worth its own issue if the silence proves confusing;
not worth blocking this one.

## Acceptance criteria

- [x] `ShopifyAdapter::blindPurchaseOffers()` returns no offers for a package whose shipment
      has another package with status `Shipped`
- [x] A shipment whose only other package is `Void` still gets the offer
- [x] A shipment whose only other package is `Unshipped` still gets the offer — two open
      drafts is not a purchase collision
- [x] `ShopifyAdapterTest` covers all three cases
- [x] A feature test asserts the purchase path refuses too, via `resolveBlindOffer()`
      re-derivation, without calling Shopify

## What this does not do

It refuses the second Shopify label; it does not produce one. Whether Shopify's
`fulfillmentOrderSplit` mutation could give a genuine label per package is
`13-split-shopify-fulfillment-orders-per-package`, which is blocked on PolyBag having a
multi-package packing workflow at all.

## Note — `supportsMultiPackage()` is dead code

The original text of this issue said `ShopifyAdapter::supportsMultiPackage()` returns false
and is merely unenforced near the pack flow. Both halves are wrong, and the correction is
worth keeping because it changes what a reader expects to find:

- `ShopifyAdapter` has no such method. Since ADR-0002 decision 7 it implements
  `BlindPurchaseSource` only; `supportsMultiPackage()` is declared on `CarrierPolicy`, which
  Shopify does not implement, because the carrier is not known until after the purchase.
- The method is consumed by **no application code at all** — USPS, FedEx, UPS and the fake
  adapter each answer it, and the only callers are assertions in `FedexAdapterTest`,
  `UspsAdapterTest` and a handful of in-test stub adapters. It is not a guard rail anywhere,
  for any carrier.

So it either needs a consumer or needs deleting, and that is a `CarrierPolicy` decision
rather than a Shopify one. It is not in scope here; nothing in this issue reads it.

## Comments

### Implemented — 2026-09-05

Landed as specified. `ShopifyAdapter::blindPurchaseOffers()` gained a fourth gate calling a
new private `shipmentAlreadyBoughtALabel()`, which asks whether any *other* package on the
shipment is `PackageStatus::Shipped`. The class docblock for the method now says "Four gates"
and names this one; the helper carries the reasoning about the shipment-level fulfillment
order and about why `Void` is not disqualifying.

Tests, four of them, all confirmed to fail with the gate disabled:

- `ShopifyAdapterTest` — no offers with a shipped sibling; offers still made with a `Void`
  sibling; offers still made with a second `Unshipped` draft.
- `BlindPurchaseTest` — "refuses to buy a second Shopify label for a shipment that already
  has one", which registers the **real** `ShopifyAdapter` into `CarrierRegistry` rather than
  the mock stand-in the rest of that file uses, and ships through
  `PackageShippingWorkflow::ship()`. It fails with `Offer No Longer Available` at
  `resolveBlindOffer()`'s re-derivation, so a stale Ship page is refused too.

Worth recording from that last one: with the gate disabled, the same test fails with
`Carrier Error` instead — the purchase went all the way to the Shopify HTTP call. That is
direct evidence the second purchase really was reachable, which is what this issue was
guessing at.

The silence noted above is unchanged: the offer simply is not listed, and the purchase-time
refusal says "not offering this option for this package" rather than naming the first label.
If that proves confusing at a bench, giving `BlindPurchaseSource` a way to report an
exclusion reason is the follow-up.

### Review follow-up — 2026-09-05

Review found two holes in the above, both real, both fixed. The shipped-sibling check as
first written was necessary and not sufficient.

**In-flight siblings bypassed a status-only check.** `ShopifyShippingLabelService` persists
`shopify_purchase_result_id` the moment Shopify accepts the mutation and
`shopify_shipping_label_id` as soon as a label exists — both deliberately *before*
`markShipped()`, so that a label download that 500s does not lose a label the shop has
already paid for. A sibling stranded in exactly that state is `Unshipped`, so the original
query cleared it and this package could buy a second label against the same fulfillment
order. `shipmentAlreadyBoughtALabel()` now disqualifies a sibling carrying either marker as
well as a shipped one.

The markers are disqualifying until something clears them, and the only thing that does is
`ShopifyFulfillmentSynchronizer::applyVoid()`, which strips both in the same write that
un-ships the package after a confirmed Shopify-side void. That is what keeps `Void` correctly
non-disqualifying: reopening the fulfillment order and clearing the markers are one act.

**The check was not atomic across packages.** `EloquentPackageShippingWorkflow::ship()` locks
on `package-purchase:{id}`, which is the right grain for postage bought against a package and
the wrong grain for a purchase bought against a shipment-level fulfillment order. Two packages
take two different locks, both revalidate cleanly while neither sibling has left a trace yet,
and both reach Shopify. Withdrawal only closes the window once the first purchase has written
something; nothing closed it before that.

`ship()` now takes a second lock, `shipment-blind-purchase:{shipment_id}`, around
revalidation and purchase together — but **only when the request carries a blind offer**.
Postage from a carrier account is per-package by nature, and serializing it would refuse a
second packer boxing a second parcel of the same shipment for no reason. The shipment lock is
taken after the package lock and never the reverse, and both are taken without waiting, so no
two requests can wait on each other.

Five further tests, and I checked which fail with each fix disabled:

- `ShopifyAdapterTest` — sibling holding `shopify_shipping_label_id`; sibling holding
  `shopify_purchase_result_id`. Both fail without the marker check.
- `BlindPurchaseTest` — "refuses a blind purchase while another package on the shipment is
  buying one", holding the shipment lock in the test. Fails without the second lock.
- Two that pass either way, kept as guards against over-correction: a voided sibling with its
  markers cleared is still offered, and a carrier-account purchase still completes while the
  shipment's blind-purchase lock is held by someone else.

Full suite green: 1992 passed, 2 skipped.
