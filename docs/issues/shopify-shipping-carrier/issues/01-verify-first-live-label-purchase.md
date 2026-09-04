# Buy the first live Shopify Shipping label and settle what comes back

Status: ready-for-human

Repo: `polybag`

## Problem

Every remaining unknown about Shopify Shipping is gated behind one manual act: buying a
shipping label through the **Shopify admin**, which is how a shop accepts the Shopify
Shipping terms of service. Until that happens the API answers every purchase with
`TERMS_OF_SERVICE_NOT_ACCEPTED`, and no amount of code changes that.

This is `ready-for-human` because it costs real postage, cannot be voided through the
API, and needs someone with admin access to the shop.

## Before starting

- The staff account needs the **`buy_shipping_labels`** permission — separate from the
  app's OAuth scopes, and not visible through the API.
- `write_orders` was granted 2026-08-29. `read_shipping` is not granted and is not
  needed; `read_orders` also authorises the shipping documents.
- **Check the store is eligible at all.** `polybag-test` is a development store, and
  Shopify Shipping is generally unavailable on those. If the admin will not sell a label
  either, this verification has to move to a real store — the code is store-agnostic, so
  that is a matter of pointing a `DataSource` at one, not a code change.

## What to answer

Fold all of it into as few purchases as possible; each one costs money and can only be
voided in the admin.

1. **PDF or ZPL?** `ShippingObjectsShippingDocument.format` reports what the shop's
   admin setting produced. This is the single most-asked question about the feature.
2. **If ZPL, at what DPI?** PolyBag currently stores its *own* configured DPI on the
   package, not Shopify's. Format follows the label correctly — printing dispatches on
   the stored `label_format` — but density does not, and a 203/300 mismatch prints the
   wrong physical size. If Shopify's ZPL density is fixed or discoverable, record it
   and file a follow-up to store it.
3. **Does the purchase create the Fulfillment itself?** This matters twice:
   - `ShopifySource::exportPackage()` calls `fulfillmentCreate` and already swallows
     "already fulfilled" errors, so export degrades safely either way.
   - `ShopifyFulfillmentSynchronizer` **reads fulfillments** to find `LABEL_VOIDED`. If
     Shopify creates no fulfillment and PolyBag's export creates it instead, confirm the
     display status still lands where the synchronizer looks. This is an untested
     assumption in shipped code.
4. **Is the customer notified twice?** `notifyCustomer` goes on the purchase, and
   PolyBag's export sets it again on `fulfillmentCreate`.
5. **What does `trackingInfo.company` actually read?** It becomes the package's **carrier
   of record**. Confirm it is a human-sensible carrier name and not an internal code, and
   that `CarrierNormalizer` resolves it — an unrecognised spelling normalizes to null,
   which is a valid terminal state but costs the ship-date cutoff and the export mapping.
6. **Is a customs form returned for an international order**, and as a separate
   `CUSTOMS_FORM` document? See `07`.
7. **Does `Fulfillment.events` return anything for a Shopify Shipping label?** Query
   `events(first: 50)` once the parcel has actually moved. The only documented way events
   are created is the `fulfillmentEventCreate` mutation, used by apps and fulfillment
   services; nothing says Shopify writes them for shipments it tracks itself.
   - Populated → read it as the event feed, with `displayStatus` as the summary; it is the
     scan-level detail (`happenedAt`, city/province/zip, lat/long, `message`) that
     `tracking_details['events']` wants and `displayStatus` cannot give.
   - Empty → `displayStatus` plus `inTransitAt`/`deliveredAt`/`estimatedDeliveryAt` is the
     whole feed, which is what shipped code already assumes.

   The query already selects the field, and an empty connection is already tolerated as a
   normal result rather than an error, so this is an observation to record, not a change to
   make. From `postage-source-split/07`.
8. **Does `displayStatus` advance past `LABEL_PURCHASED` at all?** Everything in
   `ShopifyPostageSource`'s `FulfillmentDisplayStatus` → `TrackingStatus` mapping is
   confirmed against Shopify's documentation and nothing else. If the status sits still in
   practice, that slice's tracking is inert rather than wrong — but we would want to know.
   From `postage-source-split/07`.
9. **Does Shopify accept a midnight `shippingDatetime`?** After the 8 PM cutoff,
   `getShipDate()` returns a date at midnight and `ShopifyShippingLabelService` sends
   tomorrow at 00:00; before it, `now() + 5 minutes`. Confirm Shopify does something
   sensible with the midnight value rather than rejecting it or silently substituting.
   From `postage-source-split/06`.
10. **Can a Shopify-bought USPS label go on a USPS SCAN form we create?** Only if the
    opportunity arises cheaply — this is the one question here that needs a *second*
    controlled purchase rather than an observation on the first, so it may be worth
    splitting out once a label can be bought at all.

    PolyBag currently excludes Shopify-bought postage from its manifests by provenance, and
    that gate stays until this is settled. To test: do **not** add the label to a Shopify
    manifest first; submit only that tracking number through PolyBag's existing USPS SCAN
    request using the exact label ship date and origin ZIP; record the complete USPS status
    and response body and whether the returned form includes the tracking number.

    Regardless of the result, ask USPS API support whether the use is officially supported:
    may a SCAN Forms v3 "Label Shipment" request include an IMpb created by a third-party PC
    Postage provider under that provider's MID, when the authenticated API customer is the
    physical mailer but is not the label-owner MID? EasyPost documents a stricter rule for
    its own ScanForm API — all shipments on one form must belong to the same carrier account
    (https://docs.easypost.com/docs/scan-form) — which may reflect a USPS constraint or an
    EasyPost one. Useful evidence, not an answer. From `postage-source-split/02`.

## How to run it

The whole path already works against the live store. With a real Shopify-sourced
package:

```php
$package = Package::with('shipment.dataSource')->findOrFail(<id>);
$adapter = new ShopifyAdapter;
$rates = $adapter->getRates(RateRequest::fromPackage($package), ['auto']);
$response = $adapter->createShipment(ShipRequest::fromPackageAndRate($package, $rates->first()));
```

Before the terms were accepted this returned Shopify's error verbatim, which is what
confirmed the chain end to end. Afterwards it should return a label.

## Comments
