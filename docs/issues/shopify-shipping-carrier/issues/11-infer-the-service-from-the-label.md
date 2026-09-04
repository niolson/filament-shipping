# Infer the service Shopify bought, and record how it was inferred

Status: ready-for-human

Repo: `polybag`

## Problem

Shopify never reports the service it bought. `ShippingLabel` exposes `id`, `location`,
`printed`, `cancellable`, `shippingDocuments` and `trackingInfo` — no service, no service
code, no rate, no price, before or after purchase. `10` therefore leaves `packages.service`
null, and `postage-source-split/11` landed the model that makes the absence legible:
`service_evidence` of `confirmed` / `inferred` / `unknown`, with an inference method and
ruleset version recorded whenever it is `inferred`.

Nothing produces `inferred`. Every Shopify package's service is null for its lifetime, and
`service` is what operators filter and sort by and what billing groups on.

ADR-0003 settles both halves of this already — decision 5 ("the service may later be
*inferred* from the tracking number — that is a different provenance, not a contradiction")
and a **To revisit** entry that lays out the method in full. This slice carries that entry
into work; it does not re-decide it. Read that entry before starting: everything below
elaborates it, and where the two disagree the ADR wins.

Inference is for our own reporting. It never reaches a marketplace: `confirmedService()`
already withholds anything that is not `confirmed`, and that stays true here.

## What to build

A ladder, cheapest and most reliable first, stopping at the first conclusive answer. Each
rung names itself in `service_inference_method` and stamps `service_ruleset_version`, so a
value can be re-derived and compared when the tables change.

The carrier is already known — `trackingInfo.company` is recorded at purchase — so every
rung only has to choose a service *within a known carrier*, which narrows every lookup table
below and makes an unrecognised carrier a clean stop rather than a guess.

**1. Decode the tracking number.** The carrier encodes the service in its own barcode:

- **USPS IMpb** carries a 3-digit Service Type Code identifying mail class, product and
  extra services. Source it from a current Pub 199 and the separately maintained STC list
  rather than transcribing from memory, and expect some STCs to cover several
  mail-class/extra-service combinations — those are inconclusive, not a coin flip.
- **UPS 1Z** documents a service-level indicator in bytes 9–10. Expect contract and regional
  codes that the published table does not cover.
- **DHL eCommerce, OnTrac, Canada Post** encode nothing usable. Stop, stay `unknown`.

**Validate the tracking number's format and check digit before inferring anything from it.**
A malformed or mistyped number that happens to have plausible digits in the service position
is the one way this rung produces a confident wrong answer rather than no answer.

This rung costs nothing, needs no label, and — importantly — `packages.tracking_number` is
not touched by `PurgePiiCommand`, so it is the only rung that can be re-run over historical
packages after a ruleset improves.

**2. Read the label's plaintext.** `packages.label_data` holds the bytes and
`packages.label_format` says which kind:

- **ZPL is plain text.** The human-readable service sits in a `^FD` field. No dependency,
  no parsing beyond a scan.
- **PDF needs text extraction.** No PDF-parsing library is in `composer.json` today, so
  this is a dependency decision rather than an implementation detail — see below. Shopify
  picks the format from the shop's own admin setting and the API offers no way to request
  one, so we do not get to choose the easy case.

This rung has a shelf life. `PurgePiiCommand` nulls `label_data` after `pii_retention_days`
(default 90, per-channel override, `0` keeps forever) because labels carry embedded
recipient PII. Inference therefore runs **at purchase time**, not lazily on demand — a
package inferred months later may have no label left to read.

**3. Fingerprinting or OCR.** The ADR calls OCR "disproportionate unless real data says
otherwise". Treat it as a decided non-goal rather than vague future work: do not build it
until measured coverage from rungs 1 and 2 says otherwise.

**Measure coverage against real Shopify packages**, and report it. The ADR asks for this
explicitly, and it is the evidence base for two later decisions: whether rung 3 is ever
worth building, and whether an inferred service should ever be published (see below).

**Cost stays unknown regardless.** Nothing here recovers a price — Shopify exposes none, and
no amount of service inference changes that.

**A write path, which does not exist yet.** `Package::markShipped()` is a one-shot
transition out of `Unshipped` guarded by optimistic locking, so it cannot be reused to
upgrade a package after the fact. This needs a narrow method of its own that only ever
moves `unknown` → `inferred`, never overwrites `confirmed`, never downgrades, and — when
re-run under a newer ruleset — replaces an inferred value along with its version stamp.
`Package::assertServiceEvidenceIsConsistent()` already refuses an inferred service that
cannot name both its method and its ruleset version; the new path must satisfy it rather
than route around it.

## Not in scope: publishing what we infer

`confirmedService()` withholds anything that is not `confirmed`, and that stays true no
matter how good inference gets. ADR-0003 decision 7 is categorical: channel exports publish
`confirmed` only, because a guess sent to a marketplace becomes a buyer-facing fact we
cannot retract, while omitting an optional field costs nothing.

This will come under pressure once coverage is high — a deterministic STC decode is a poor
example of "a guess". Two notes for whoever argues it:

- The evidence to argue it with is the coverage measurement above, not intuition. ADR-0003
  was itself accepted only once its premise was measured rather than argued.
- If it is ever granted, the change is to the **export rule** — publish `confirmed` plus a
  named allowlist of inference methods — and never to relabel a decoded value as
  `confirmed`. `confirmed` has to keep meaning "the postage source reported it", or the
  distinction the whole model rests on quietly disappears.

## What to answer

1. **Do we take a PDF text-extraction dependency, or restrict rung 2 to ZPL?** Restricting
   it means shops whose admin is set to PDF get no inference beyond the tracking number.
2. **Is OCR ever in scope, or explicitly `wontfix`?**
3. **How are ruleset versions numbered**, and where do the STC and 1Z tables live —
   `resources/data/` holds only carrier test cases today, and carrier reference data is
   otherwise seeded from source.

## Acceptance criteria

- [ ] A USPS tracking number with an unambiguous Service Type Code yields the service with
      evidence `inferred`, and both the method and the ruleset version recorded
- [ ] An ambiguous Service Type Code, or a carrier that encodes nothing, falls through
      rather than picking one
- [ ] A ZPL label yields the service where the tracking number was inconclusive
- [ ] Exhausting every rung leaves the package `unknown` with its requested preference
      intact — never a guess written as the service value
- [ ] An inferred service is never written over a confirmed one, and never downgrades
- [ ] Re-running under a newer ruleset replaces the value and its version stamp together
- [ ] Nothing inferred reaches a channel export
- [ ] The STC and service-indicator tables are versioned data, not literals inline in a
      service class
- [ ] A tracking number failing format or check-digit validation infers nothing
- [ ] Coverage against real Shopify packages is measured and reported — what fraction each
      rung resolved, and what was left `unknown`

## Blocked by

- `01-verify-first-live-label-purchase` — there is no real Shopify label to validate any of
  this against until one has been bought
- `postage-source-split/11-service-provenance-and-evidence` — done; the model this writes into
