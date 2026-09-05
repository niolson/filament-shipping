# Represent Shopify as a blind purchase, not a fabricated rate

Status: done — 2026-09-04

Repo: `polybag`

## Problem

`ShopifyAdapter::getRates()` returns `RateResponse` objects carrying an invented price, an
invented service name and `Shopify` as the carrier. None of those are facts. ADR-0003
decisions 5 and 6 reject that model outright: Shopify's `ShippingLabel` exposes no service and
no price before or after purchase, and omitting `preferredRateSelection` gives Shopify an
unconstrained choice — buyer-selected method, then shop preference, then Shopify's own
recommendation. It is a blind purchase, not a rate.

Leaving the fabricated `RateResponse` in place would quietly restore the model the ADR rejects.

## What to build

A **priceless offer** — a type of its own, not a `RateResponse` — presented alongside rates in
the Ship page list, visually separated, requiring explicit confirmation, and never entering any
comparison or ranking. Not a separate screen: moving the one path that saves ~25% off the
screen where packers choose is how it ends up unused.

Policy from ADR-0003 decision 5:

- Excluded from **every** automated path — auto-ship, batch ship, shipping rules,
  `RateSelector::selectBest()` — reachable only where a client has explicitly opted into blind
  purchase. Note `RateSelector`'s docblock currently states an unknown-price rate "only wins
  when nothing else is offered", which is acceptable attended and not acceptable unattended.
- The Ship page must warn explicitly that price and service are unknown until purchase.
- A shipment carrying a **hard-required special service** must exclude Shopify, since nothing
  about an unconstrained selection can guarantee one.

## Acceptance criteria

- [x] `ShopifyAdapter` no longer returns `RateResponse` objects — it no longer implements
      the interface that could return one
- [x] The offer is selectable by a human, with explicit confirmation, and never ranked
- [x] No automated path can reach it — with or without the opt-in, see the deviation below
- [x] A hard-required special service excludes Shopify, visibly
- [x] `ShopifyAdapterTest` and `RateSelectorTest` cover both exclusions

## Blocked by

- `postage-source-split/08-split-carrier-adapter-interface`

## What shipped

A type, two contracts, a column, and a second radio group.

- **`BlindPurchaseOffer`** — source, source label, service code, selection label, postage
  data source. No price field, deliberately: the invented `0.00` the ADR objects to has
  nowhere to go, so nothing downstream can sort, rank or compare it.
- **`BlindPurchaseSource`** — `blindPurchaseOffers()`, implemented by `ShopifyAdapter`
  alone. Splitting it out of `CarrierAdapterInterface` needed a base contract for what
  every source answers whether or not it quotes, so `PostageOfferSource` now holds
  `getCarrierName()`, `isConfigured()`, `offerCapability()`, `offerDeclaredValueCap()`
  and `createShipment()`, and `CarrierAdapterInterface` is the quoting half alone.
  `CarrierRegistry` is keyed on the base and gained `quotingAdapterFor()`,
  `blindPurchaseSourceFor()` and `blindPurchaseSourceNames()`.
- **`clients.blind_purchase_enabled`** — off by default, edited per client in Clients and,
  for a single-client install, in Settings. `shipments.client_id` is NOT NULL, so every
  install has somewhere to put the consent.
- **The Ship page** lists offers in their own block below the rates, dashed and warning-
  coloured, behind a panel saying price and service are unknown, and selecting one clears
  the rate selection (and vice versa). Shipping opens a confirmation modal naming what is
  not known — including that the label cannot be voided from PolyBag — and only the
  confirmation buys anything. The consent resets after every attempt.
- **`ShipRequest`** carries either a `selectedRate` or a `blindOffer`, never both, with
  `fromPackageAndBlindOffer()` assembling everything a label needs that does not come from
  a quote. `PackageShippingRequest` enforces the same exclusivity in its constructor.

`ShopifyAdapter` lost `getRates()` and `resolvePreSelectedRate()` outright rather than
stubbing them, which is what makes the first acceptance criterion structural: there is no
method left that could return a `RateResponse`.

Decisions the issue left open, and why they went the way they did:

**Automation is excluded structurally, not by permission.** The criterion said "no
automated path can reach it *without a client opt-in*", which reads as: with the opt-in,
automation may. It is implemented stricter — no automated path reaches a blind purchase at
all — because `selectBest()`, auto-ship, batch ship and shipping rules are all typed in
`RateResponse` and a `BlindPurchaseOffer` is not one. Building the permission would have
meant giving automation a way to handle the type, which is work in the opposite direction
from ADR-0003 decision 5. The opt-in still gates everything: without it the offer is never
advertised and the purchase is refused.

Three places enforce it, because they fail differently:

- `RateSelector::selectBest()` now **drops** unpriced rates and returns null when nothing
  priced is left, where it used to sort them last and let one win alone. `classify()` is
  unchanged — the attended list still shows them, and that is the distinction the ADR
  draws. The return type became nullable; the one caller already handled "no rate".
- `RuleEvaluator` skips a `UseService` rule naming a blind-purchase source and carries on
  to the next rule, so a rule that should never have existed behaves as if it did not.
  `ShippingRulesRelationManager` no longer offers those services, so no new one can be
  written. Neither is a name check: both ask the registry.
- `selectedRateForAutoShip()` resolves a pre-selected rate through `quotingAdapterFor()`
  and falls through to rate shopping when there is no quoting adapter, then refuses any
  rate that comes back `priceUnknown`.

**The offer is re-derived at the purchase, not trusted.** `blindPurchaseOffers` is a public
Livewire property, so what comes back is the client's words: source, service code and
labels alike. The first implementation checked only consent and required services against
it, which left an opted-in user able to name a selection outside the shipping method, or
one a hard-required service had just excluded, and have it bought. Caught in review.

`ShippingRateService::blindPurchaseOffersFor()` now answers "was this ever offered?" by
building the same carrier tasks quoting builds — shipping method, destination,
special-service capability, each source's own gates — and asking only the blind-purchase
sources, skipping `fetchRatesConcurrently()` entirely so no carrier is called and no money
is spent finding out. `resolveBlindOffer()` matches the incoming offer by identifier and
**buys the server's copy**, exactly as `rateFromOffer()` does for a quoted rate: what comes
back says which offer and nothing more.

That collapses what had been two enforcement points into one. The hard-required special
service is checked by `buildCarrierTask()` alone — the same code and the same
`ServiceCapability::Unguaranteed` from `postage-source-split/08` that produces the Ship
page's exclusion notice — and the refusal quotes that reason back rather than restating the
rule in a second wording. Extracting `buildCarrierTasks()` out of `getShippingRates()` is
what made the sharing possible, and is the only change to quoting behaviour: none.

**Consent is still checked twice, and separately.** `ShopifyAdapter::blindPurchaseOffers()`
checks it so nothing is ever advertised; `resolveBlindOffer()` checks it before the
re-derivation, because a client who has not opted in produces an empty list too, and
"no longer available" would send an operator looking for the wrong thing.

**A blind offer has no `ShippingOffer` row.** The offer store from
`amazon-buy-shipping/02` exists for things that can be spent twice: opaque tokens, expiry,
atomic consumption. A blind offer holds no token and expires never — it is an
advertisement that this source will sell a label, not a quote — so it stays in page state,
and what comes back is matched against the offers the page produced rather than trusted.
An identifier naming none of them selects nothing.

**Nothing is written to `rate_quotes`.** `markSelected()` is skipped for a blind purchase:
there was no quote to mark, and inventing a row for the audit log would put the fabricated
price back one layer down.

**`AsyncRateQuoting` now extends `CarrierAdapterInterface`.** Not planned here, but the
split made it visible: `prepareRateRequest()` may decline and the caller then asks
`getRates()` for the same quote, so anything quoting asynchronously must also quote
synchronously. It was previously true by luck of every implementer pairing the two.

**The Alpine highlight reads server state.** The rate list mirrored `selectedRateIndex`
into an Alpine variable; with a second list able to take the selection away, two copies of
"what is selected" would disagree the moment it did. It is a getter over `$wire` now.

### Test churn

`ShopifyAdapterTest` kept its three `getRates` cases as `blindPurchaseOffers` cases and
gained three: that the adapter is not a `CarrierAdapterInterface` at all, that a client
who has not opted in is offered nothing, and that a `createShipment` handed a rate instead
of a blind offer is refused. `RateSelectorTest`'s "picks a rate priced at
purchase only when nothing else is offered" became "never buys a rate whose price nobody
has seen" — the assertion reversed on purpose, and it is the one the ADR names.
`ShippingRateServiceTest`'s Shopify default-service case now asserts the offer comes back
through `getBlindPurchaseOffers()` and the rate list stays empty.

`tests/Feature/BlindPurchaseTest.php` is new and holds the path end to end: offered beside
the rates and never pre-selected, nothing bought until the modal is confirmed, the
purchase carrying the offer rather than a rate, one selection at a time, both refusals,
the visible exclusion, no auto-ship, and the ignored shipping rule. Three of its cases are
the forged-request ones the review asked for — a selection the source never advertised, an
offer attributed to a source that only quotes, and a tampered offer whose identifier is the
only part that survives.

### Not done here

- `packages.service` inference is still `11-infer-the-service-from-the-label`. A blind
  purchase records `service: null` with evidence `unknown`, as `10` left it.
- Nothing here needed the offer store, so the two remain unrelated. If a future source
  sells a blind purchase against a token — none does today — that is when they meet.
