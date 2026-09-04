# Record postage-source provenance on new shipments

Status: done — PR #155, amended by PR #158

Repo: `polybag`

## Problem

`packages.carrier` does two unrelated jobs — adapter dispatch key and carrier of record —
and nothing records *where the postage was bought*. See `docs/adr/0002-carrier-of-record-vs-postage-source.md`
in the app repo, decision 2.

This slice adds the record without touching a single existing row. The backfill and the
query changes that depend on it are `02`.

## Update — 2026-09-02, after `01` merged

Confirmed with the maintainer: **no Shopify Shipping label was generated before this
slice landed, in development or in production.** Every package predating the discriminator
was therefore bought directly from a carrier; there is no historical package whose postage
provenance is genuinely unrecoverable.

`legacy_unknown` consequently describes a state that cannot occur, and no backfill will
ever write it. PR #158 removed it from `PostageSource`; the discriminator has exactly the
two reachable values below. Slice `02` records known historical direct purchases as
`carrier_account` rather than inventing an unknown provenance. The specific
`carrier_account_id` remains nullable: historical and fake-carrier purchases can be known
to be direct even when no particular `CarrierAccount` row identifies them.

## What to build

A migration adding to `packages`:

- `postage_data_source_id` — nullable FK to `data_sources`
- a `postage_source` discriminator taking exactly `carrier_account` or
  `postage_data_source`

`Package::markShipped()` must receive the discriminator as an argument and write it inside
its existing `DB::table('packages')->update()`. That call bypasses model events for
optimistic locking, so a model observer or a saving hook would never fire — the enforcement
has to live in the method itself.

## Acceptance criteria

- [x] Migration adds both columns; no existing row is modified
- [x] `markShipped()` takes the discriminator and writes it atomically with the rest of the ship
- [x] A direct-carrier purchase records `carrier_account`, records `carrier_account_id`
      when an account was resolved, and leaves `postage_data_source_id` null
- [x] A Shopify purchase records `postage_data_source` + `postage_data_source_id`
- [x] `postage_data_source` requires its matching pointer; `carrier_account` permits a null
      account pointer; neither provenance may set the other provenance's pointer. A
      violation raises rather than writing a half-state
- [x] No `legacy_unknown` enum case or write path exists
- [x] Tests cover both provenances and the inconsistent-write rejection

## Blocked by

None — can start immediately.
