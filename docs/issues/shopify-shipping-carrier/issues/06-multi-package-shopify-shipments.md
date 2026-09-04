# Decide what happens when a Shopify shipment needs more than one package

Status: needs-triage

Repo: `polybag`

## Problem

One Shopify fulfillment order buys **one** label. `ShopifyAdapter::supportsMultiPackage()`
returns false, but PolyBag lets a packer split a shipment across several packages, and
nothing stops the second one from being shipped via Shopify.

What actually happens is untested. The expected failure is `JOB_NOT_ENQUEUED` ("There is
another shipping label being purchased for this order") or `FULFILLMENT_ORDER_INVALID`,
both of which the adapter surfaces verbatim to the packer. That is probably a
comprehensible message, but "probably" is doing real work in that sentence, and multi-box
orders are not rare.

## What to establish

- What Shopify actually returns for a second purchase against an already-fulfilled
  fulfillment order, and whether the message means anything to a packer at a bench.
- Whether Shopify's `SPLIT` fulfillment-order action is a genuine route to one label per
  package, or a complication not worth the trouble.
- Whether a Shopify-sourced shipment should refuse a second package earlier — at pack
  time rather than at ship time, so the failure lands before a box is taped shut.

## Note

`supportsMultiPackage()` returning false is not currently enforced anywhere near the
pack flow; it describes the adapter, not a guard rail. Check what actually consumes it
before assuming it protects this case.

## Comments
