# Represent Shopify as a blind purchase, not a fabricated rate

Status: ready-for-agent

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

- [ ] `ShopifyAdapter` no longer returns `RateResponse` objects
- [ ] The offer is selectable by a human, with explicit confirmation, and never ranked
- [ ] No automated path can reach it without a client opt-in
- [ ] A hard-required special service excludes Shopify, visibly
- [ ] `ShopifyAdapterTest` and `RateSelectorTest` cover both exclusions

## Blocked by

- `postage-source-split/08-split-carrier-adapter-interface`
