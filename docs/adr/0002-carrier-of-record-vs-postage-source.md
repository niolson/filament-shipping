# ADR-0002: Record the carrier of record separately from the postage source

## Status

Accepted — 2026-09-01

## Context

`packages.carrier` is a denormalized string, not a foreign key to `carriers`. It does two
unrelated jobs:

- **Adapter dispatch key** — `TrackingService::refreshPackage()` and
  `EloquentPackageLabelWorkflow` both do `carrierRegistry->get($package->carrier)` to
  answer "who do I call to track or void this?"
- **Carrier of record** — the audit log, `AggregateShippingStats`, the channel export in
  `PackageExportService::buildExportData()`, the manifest grouping in
  `ManifestService::getUnmanifestedPackages()` and `EndOfDay`, and the USPS cutoff rule in
  `ShipDateService::getShipDate()` all read it as "who is carrying this parcel?"

Those were the same value while every label was bought from the carrier that carries it.
Shopify Shipping (daf1bd8, 2026-08-29) ended that: it buys postage on the merchant's
Shopify account and Shopify picks the carrier, so the package is written with
`carrier = "Shopify"` while the real carrier lands in `packages.service` and
`metadata.shopify_tracking_company`. Shopify is not a carrier, and three behaviors are
already wrong because the column says it is:

1. **The channel export reports a non-carrier.** `PackageExportService` passes
   `$package->carrier` to the destination; `AmazonSource::CARRIER_MAP` has no `Shopify`
   key, so a Shopify-bought label on an Amazon order confirms as
   `carrierCode: "Other", carrierName: "Shopify"` carrying a USPS tracking number. The
   buyer gets a tracking link that does not resolve.
2. **Ship-date cutoffs miss.** `ShipDateService` applies the 8 PM USPS cutoff by carrier
   name. A Shopify-bought USPS parcel passes `"Shopify"`, skips the rule, and is dated for
   a pickup it will not make.
3. **Tracking dispatches on the wrong axis.** `ShopifyAdapter::supportsTracking()` is false
   because *Shopify* has no tracking API, so a real USPS parcel with a real USPS tracking
   number reports as untrackable. The reason it stays untrackable turns out not to be the
   model's fault (see the USPS entitlement note below) — but the code arrives at the right
   answer by accident, through a carrier name that is not a carrier.

Amazon Buy Shipping, the next integration, makes the conflation actively lossy rather than
merely wrong. Its `getRates` returns `carrierName` per rate and offers USPS, UPS and Amazon
Shipping side by side; filing all of them under one "Amazon Buy Shipping" carrier row
discards what the API is handing us. Amazon also introduces a third shape the current model
has no room for: **Amazon Shipping on a non-Amazon order**, where the carrier is Amazon
Shipping and the postage is bought through a carrier account rather than a data source.

## Decision

Treat these as two axes and record both on the Package.

**1. `packages.carrier` always names the physical carrier.** USPS, UPS, FedEx, Amazon
Shipping. Never a marketplace, storefront, or reseller. It stays a denormalized string
deliberately, not as a legacy artifact: it records what the carrier of record *was*, including
values we hold no `Carrier` row for and never will. See option D below.

**2. The postage source is where the label was bought**, and is exactly one of:

| Bought through | Pointer | Exists |
|---|---|---|
| A direct carrier account | `packages.carrier_account_id` | yes |
| Sales-channel postage (Shopify Shipping, Amazon Buy Shipping) | `packages.postage_data_source_id` | **new, nullable FK** |

Named `postage_data_source_id`, not `data_source_id`: it records where the postage was bought,
which is not necessarily the shipment's import source and must not be confused with it.

Which of the two applies is recorded by an explicit **discriminator**, not inferred from which
pointer happens to be null. Two nullable columns cannot tell a deliberately recorded legacy
package apart from missing or corrupt data. The discriminator takes exactly three values:

| Value | Pointer set | Meaning |
|---|---|---|
| `carrier_account` | `carrier_account_id` | bought directly from the carrier |
| `postage_data_source` | `postage_data_source_id` | bought through sales-channel postage |
| `legacy_unknown` | neither | shipped before this change; provenance genuinely unrecoverable |

Consistency rule: the discriminator and its matching pointer agree, and no other pointer is set.
`legacy_unknown` is writable only by the backfill, never by a new purchase.

The invariant is scoped to **every new transition to `Shipped`**, and has to be enforced inside
`Package::markShipped()`, which must receive the discriminator as an argument: it writes through
`DB::table('packages')->update()` for optimistic locking, bypassing model events, so a
model-level guard would never fire. Test fixtures are not a domain exception — they satisfy the
invariant like anything else.

**3. Dispatch splits along the axis that actually owns the operation:**

- Rates, label purchase, void, and manifest eligibility → **postage source**. Only whoever
  bought a label can void it or manifest it.
- Ship-date cutoffs, channel export, and reporting → **carrier**, *except for Shopify*, which
  cannot participate: `shippingDatetime` has to be sent in the purchase mutation, before
  Shopify reveals which carrier it picked. Learning afterwards that it chose USPS cannot
  retroactively change the label's ship date. Shopify needs a conservative source-level
  policy — the earliest relevant cutoff across the carriers it might pick — not a
  carrier-derived one.
- Tracking → **by postage source, not as a fallback chain.** The entitlement to tracking data
  follows whoever bought the label, not whoever carries the parcel. An Amazon Buy Shipping
  label tracks through Amazon's `getTracking`. A Shopify-bought label is **terminally
  untrackable** — not "try the carrier next," since we hold no USPS entitlement for it. Carrier
  dispatch applies only to direct provenance and to explicitly-marked legacy packages. See the
  entitlement note.

**4. A selectable rate carries an opaque purchase context, not just a description.** Every
rate must carry:

- the specific **postage source instance** it came from;
- an **opaque purchase context** — for Amazon, the `requestToken` and `rateId` from `getRates`,
  both of which `purchaseShipment` requires and neither of which can be reconstructed from a
  carrier and a service name;
- **carrier and service as descriptive facts**, never as purchase identity.

This decision governs *rates*. Shopify is not a rate and is out of scope here: it is a
**blind-purchase option** with no price, no service and no carrier until after purchase — see
ADR-0003 decisions 5 and 6.

The purchase context stays **server-side**. Rates round-trip through the browser today —
`RateResponse::toArray()`/`fromArray()` exist for Livewire serialization — and `requestToken`,
`rateId`, source identity, environment and expiry must not be authoritative browser state. The
browser selects an **opaque internal offer identifier**; the server holds and validates the
real context. One change closes tampering and replay, offer expiry, direct-versus-Amazon
collisions, several offers for one carrier/service, and exact quote-log selection.

That store is new. `rate_quotes` is an audit log on a 60-day purge, not an authoritative
purchase context, and must not be repurposed as one. The offer store needs: an opaque
identifier, binding to both the package and the postage-source instance, an expiry, atomic
consumption so one offer cannot be spent twice, and idempotent recovery so a purchase that
succeeded at the source but failed on our side is found rather than repeated — the property
`ShopifyShippingLabelService` already has and must not lose.

Identity matters because rate shopping across sources makes collisions real rather than
theoretical. `EloquentPackageShippingWorkflow::selectedRateIndex()` matches a pre-selected rate
on `carrier` + `serviceCode` alone, and `RateQuoteLogger::markSelected()` runs an `update()` on
the same pair — so USPS Ground Advantage offered directly *and* through Amazon would resolve to
whichever came first and would mark **both** quote rows selected.

**5. The carrier of record is normalized for behavior while the raw value is preserved.**
Free text is right for audit history, but operational logic cannot depend on a source's
spelling. `USPS`, `US Postal Service` and whatever Amazon returns in `carrierName` must resolve
to one optional normalized carrier identity, with the raw string kept alongside. The normalized
value is **snapshotted onto the package when it ships**, not recomputed on read: normalization
rules are editable, and recomputing would let an alias edit retroactively rewrite what a past
export or report meant. Normalization
must run **before** any carrier-policy lookup, and cannot itself be built on the name-keyed
`CarrierRegistry` — that registry is the consumer of normalization, not its source. Without this
the split does not actually fix the exact-string consumers it is meant to fix —
`ShipDateService::getShipDate()` compares `$carrierName === 'USPS'`, and the channel export
maps through `AmazonSource::CARRIER_MAP`. Normalization may resolve to nothing, which is the
unmapped terminal state.

**6. `CarrierRegistry`, keyed by carrier name, stops being the dispatch mechanism for
purchase-side operations.** It stays correct for carrier-side ones.

**7. `CarrierAdapterInterface` splits rather than growing role markers.** It currently bundles
quoting, purchasing, voiding, tracking, manifests, special-service policy and rate resolution,
which forces `ShopifyAdapter` to implement most of it as advertised rates, empty parsing,
unsupported operations and no-ops. Two seams are now proven: **postage-source operations**
(quote, purchase, void, **manifesting**, entitled tracking — matching decision 3) and **carrier
policy** (normalization, cutoffs, pickup and reporting behavior). Splitting stops Amazon and
Shopify adapters having to pretend to be physical carriers.

**8. Hard-required special services are evaluated at the offer/postage-source seam**, not purely
as carrier policy. `serviceCapability()` reads as carrier policy only because every offer used
to come from a direct carrier. Concretely: an **Amazon** offer is judged on the capabilities the
offer itself returns (`availableValueAddedServiceGroups` is per-rate, not per-carrier); a
**direct-carrier** offer consults carrier policy as it does today; **Shopify** is unsupported
outright, since nothing about its unconstrained selection can guarantee a required service.
Carrier policy remains the right home for capabilities that really are carrier-wide.

**9. Postage-source resolution has explicit precedence.** "Follow the `CarrierAccount` pattern"
is not enough to bind an offer to a source instance. The rules:

- **Shopify binds to the shipment's originating data source and nothing else.** A purchase is
  keyed to a fulfillment order that exists in exactly one Shopify account, so a Shopify source
  is never a candidate for a shipment that did not come from it. This is already how
  `ShopifyShippingLabelService::dataSourceFor()` behaves; the ADR records it as a rule rather
  than an implementation detail.
- **Amazon Buy Shipping binds the same way** — the order lives in one seller's account.
- **Amazon Shipping on non-Amazon orders** resolves through `CarrierAccount` scoping, where the
  existing `(location, client)` precedence already applies.
- **One source is quoted per carrier by default.** Quoting several sources for the same carrier
  is opt-in, mirroring `CarrierAccountScope::rate_shop`, because each extra source is another
  API call on the packer's critical path.
- Ties are resolved by the same priority ordering `CarrierAccount::resolveForShipment()` uses;
  an unresolvable tie is a configuration error surfaced to the operator, never an arbitrary
  pick.

## Options considered

**A. Status quo — the carrier string doubles as the dispatch key.** Rejected: it is already
producing the three defects above, and it discards Amazon's per-rate `carrierName`.

**B. Every postage source becomes a `Carrier` row** (the current trajectory, and what
Shopify did). Rejected: it requires "Amazon Buy Shipping" carrier rows that throw away the
real carrier; it leaves `ShipDateService`, the manifest grouping, and the channel export
keyed on a value that is not a carrier; and `CarrierService` rows become a source × carrier
× service product rather than a catalog of services.

**C. Split the two axes** — this ADR.

**D. Full normalization — replace the string with `packages.carrier_id`.** Rejected, and the
denormalized string is now a deliberate choice rather than a legacy artifact. Shopify picks
the carrier itself and may pick one we have no row for and no reason to create — an Italian
courier on a single parcel. A shipped package must be able to name a carrier that does not
exist in our catalog, so **unmapped is a valid terminal state** and the carrier of record has
to survive as free text. See ADR-0003.

## Trade-off

The cost lands on the selection axis. `ShippingMethod → CarrierService` is how an operator
says "this ships Ground Advantage." Under option B, "buy through Shopify, let Shopify
choose" is expressible as a `CarrierService` row, which is why it was built that way. Under
this decision it is not a carrier service at all and needs its own representation.

We accept that cost. With Shopify the carrier genuinely is not known until the purchase
response comes back, and encoding "unknown" as a fake carrier named after the storefront is
precisely what produces the three defects. A model that admits the carrier is unknown at
quote time is the honest one.

## Consequences

Easier:

- Tracking an Amazon Buy Shipping label through Amazon's `getTracking`, which today would
  have no route at all.
- Reporting a real carrier code to Amazon and Shopify on export.
- The USPS cutoff, per-carrier stats, and per-carrier pickup days all mean what they say.
- Adding a postage source no longer requires inventing a carrier that does not exist.

Harder:

- Two-key dispatch instead of one, and `CarrierAdapterInterface` splits in two (decision 7).
- Every rate now carries purchase identity, so rate DTOs, Livewire round-tripping, quote logging
  and pre-selected-rate matching all have to key on more than carrier + service.
- Every existing query on `packages.carrier` has to be audited for which of the two meanings
  it intended.

To revisit:

- Whether Shopify-bought labels ever become trackable. Only two routes exist: Shopify adds a
  tracking API, or the merchant obtains a USPS Merchant Access Token from Shopify. Neither is
  in our control.
- Whether `CarrierService` is the right selection primitive for channel postage at all.

## Note: tracking entitlement is not ours to grant

USPS changed tracking access control on 2026-04-01. Free access to the Tracking API requires
either that the MID embedded in the package barcode is registered to us, or that the shipper
authorized us through one of their active Merchant Access Tokens in the USPS Business Portal.
Everything else is paid: a signed IP agreement and Tracking Data Services, entry tier around
$599/month for 100k calls, reportedly on a non-cancellable annual term.

A Shopify- or Amazon-bought USPS label carries **their** MID, not the merchant's. So no free
entitlement exists for those parcels and the Merchant Access Token route means persuading
Shopify to authorize an individual merchant, which is not a realistic path. Directly-bought
labels are unaffected — our own MID, free, exactly as today.

This is why tracking dispatches on the postage source rather than the carrier. USPS's own API
now keys on who bought the label, which is the same distinction this ADR draws, enforced from
the carrier side. USPS PTR2 tracking semantics are documented separately; this note covers entitlement only.

## Migration hazard

`ManifestService::getUnmanifestedPackages()` groups by `packages.carrier` and `EndOfDay`
filters the same way. Shopify-bought labels are excluded from the USPS SCAN form **only**
because their carrier column reads `"Shopify"` — correct behavior for the wrong reason.
Rewriting those rows to `"USPS"` without simultaneously adding a postage-source filter would
sweep labels we did not buy, and cannot manifest, into a USPS SCAN form. The query change
must land in the same commit as the backfill, not after it.

The backfill window is small and closing: Shopify Shipping shipped 2026-08-29, days before
this ADR, so almost no affected rows exist yet. `carrier` is recoverable from
`metadata.shopify_tracking_company`, falling back to `service`; `postage_data_source_id` from the
shipment's data source. This is the cheapest this change will ever be.

## Implementation

Tracked as issues, not here — the work is sliced in the private ops repo under
`docs/issues/postage-source-split/`, with the Amazon follow-on in
`docs/issues/amazon-buy-shipping/`. This document records the decision and why; it is not a
checklist and does not get updated as work lands.
