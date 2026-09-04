# Apply ship-date cutoffs by normalized carrier

Status: done — PR #162

Repo: `polybag`

## Problem

`ShipDateService::getShipDate()` applies the 8 PM USPS cutoff by comparing
`$carrierName === 'USPS'`. Any carrier value that is not exactly that string skips the rule
and the package is dated for a pickup it will not make.

This is defect 2 of the three in ADR-0002's context.

## What to build

Key the cutoff on the normalized carrier identity rather than the raw string, for direct
carriers and for Amazon Buy Shipping — both of which know the carrier before the label is
bought.

**Shopify is out of scope here.** Its `shippingDatetime` has to be sent in the purchase
mutation, before Shopify reveals which carrier it picked, so no carrier-derived cutoff can
apply. Route it to a conservative default and leave the real policy to `06`.

## Implementation update — 2026-09-03

PR #162 resolves the carrier through `CarrierNormalizer` before *every* carrier-policy lookup
in `ShipDateService`: cutoff, pickup days, `last_end_of_day_at`, and the `endShippingDay()`
write. `getPivot()` now matches on `carrier_id` instead of joining `carriers.name` against
the raw string, so the read and write sides of the pivot agree on one identity. Normalizing
to nothing is honored as the terminal state ADR-0002 describes — an unmapped carrier gets no
cutoff and the default pickup days rather than an accidental rule hit.

The cutoff itself moved out of code and onto **`carriers.pickup_cutoff_hour`**. A map keyed
on the carrier's name would have satisfied the letter of this issue while still breaking:
the name is an editable field in the carrier form, so renaming a carrier would silently
disable its cutoff while preserving the same normalized identity that shipped packages point
at. Hanging the policy off the row removes that failure mode entirely. The migration
backfills existing installs, matching USPS by name *or* by an alias whose lookup key
resolves to it; name matching is correct there and nowhere else, since a migration acts on
the data as it stands the moment it runs. The backfill was verified against a throwaway
database seeded to look like an existing install — including a tenant that had renamed USPS
and kept an alias — because a silent failure would drop the 8 PM cutoff for every tenant at
once.

Shopify takes an explicit interim path rather than a derived one. The first draft used the
earliest of the known carrier cutoffs, which quietly made Shopify's behavior a function of
which other carriers happen to have cutoffs configured, and presented an unresolved choice
as though it were decided. It is now a fixed named constant, documented as interim and
deliberately not derived, so adding a carrier cutoff cannot move Shopify's ship dates before
`06` makes the call. The value matches today's earliest cutoff, so behavior is unchanged.

`pickup_cutoff_hour` is deliberately **not** exposed in the carrier form. Operator-controlled
cutoffs are one of the options `06` is still weighing, and an operator-set cutoff on the
Shopify carrier row would have ambiguous meaning against the interim path. It is seeded
reference data for now — the same reach the old constant had, correctly keyed.

Callers needed no change: `ShipRequest::fromPackageAndRate()` passes `$rate->carrier` and
`ShippingRateService` passes the registry name, and both normalize inside the service. That
also covers Amazon Buy Shipping's per-rate `carrierName` when it lands. A small fix rode
along — three duplicated `json_decode` fallbacks collapsed into one helper, which also stops
`getPickupDays()` returning `null` for a pivot row with null `pickup_days`.

## Acceptance criteria

- [x] Cutoff resolution uses the normalized identity, not the raw string
- [x] A USPS parcel gets the 8 PM cutoff regardless of how the source spelled "USPS"
- [x] Shopify packages take an explicit conservative path rather than falling through to the
      default pickup days by accident
- [x] `ShipDateServiceTest` covers the normalized lookup

## Blocked by

- `03-normalize-carrier-of-record` — merged 2026-09-02 (PR #160)
