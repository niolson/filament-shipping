# Add carrier of record and postage source to `CONTEXT.md`

Status: done — PR #157, amended by PR #158

Repo: `polybag`

## Problem

ADR-0002 splits one overloaded column into two concepts with distinct names, and
`AGENTS.md` requires those terms be preserved in code and prose. `CONTEXT.md` is where
agents look for the domain glossary, and it currently has no vocabulary for either — which
means the next person to touch this area reads `packages.carrier` and reasonably assumes it
still means what its name says.

Ideally this lands **with or before `01`**, so the glossary defines the terms in the same
change that introduces them to the schema. It has no code dependency and can start now.

## Update — 2026-09-02, after PR #157 merged

PR #157 added the glossary entries, relationships, and example dialogue. It deliberately
split the proposed **Observed offer** term into **Offer** and **Observed service**, matching
ADR-0003's distinction between an ephemeral package-specific quote and a durable service
identity observed in a source response.

PR #158 subsequently removed the unreachable `legacy_unknown` postage source. The
relationship now states the stronger domain rule: a Package with no recorded postage source
has not shipped, and there is no shipped Package whose postage source is unknown.

## What to build

Entries in `CONTEXT.md` following its existing shape — a `## Language` definition, one or
more `## Relationships` bullets, and an `## Example dialogue` exchange if the distinction
needs one.

The terms:

- **Carrier of record** — the physical carrier that will actually move the parcel. Free
  text, deliberately: it may name a carrier we hold no `Carrier` row for and never will.
  _Avoid_: shipping provider, shipper.
- **Postage source** — where the label was bought: a `CarrierAccount` (direct) or a
  `DataSource` (sales-channel postage). Distinct from the shipment's import source, which
  may be a different thing entirely.

Relationships worth stating: exactly one postage source per shipped Package; the carrier of
record is not known until after purchase when the postage source is Shopify; and an absent
postage source means the Package has not shipped, not that its provenance is unknown.

ADR-0003 adds four more that belong in the same pass:

- **Service class** — what a `ShippingMethod` actually is: a speed/price tier that several
  concrete carrier services can satisfy.
- **Offer** — an ephemeral, package-specific quote with a price, promise, purchase token,
  and expiry.
- **Observed service** — a durable service identity seen in a source's response, not yet
  part of the catalog. Distinct from a `CarrierService`, which is authored.
- **Blind purchase** — buying postage where the price and service are not known until after the
  fact, and in Shopify's case never. _Avoid_: rate, quote.

`Package Draft` is the model for how much detail an entry warrants — a short definition
plus the misuse it prevents.

## Acceptance criteria

- [x] Both terms defined under `## Language` with `_Avoid_` lines where a wrong synonym is
      already in circulation
- [x] Relationships section states the one-postage-source rule and the Shopify exception
- [x] Nothing in the entries duplicates the ADR's reasoning — the glossary says *what the
      terms mean*, the ADR says why

## Blocked by

None — can start immediately, and is most useful before `01`.
