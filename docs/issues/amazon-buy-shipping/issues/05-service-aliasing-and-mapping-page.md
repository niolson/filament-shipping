# Add service aliasing and a "Map Carrier Services" page

Status: ready-for-agent

Repo: `polybag`

## Problem

ADR-0003 decision 2 keeps raw Amazon observations out of `CarrierService` — observation must
not silently rewrite authored configuration, and `carrier_services.carrier_id` is a
non-nullable FK, so an offer naming a carrier we have no row for could not be stored there
anyway. That case is not hypothetical: the production run returned **OnTrac** as the cheapest
eligible offer, and we have no OnTrac row, account or adapter.

So there has to be a way to say "this observed Amazon service is our Ground Advantage", and a
place to do it.

## What to build

Source-scoped service aliasing plus the page to manage it, modeled directly on the import
mechanism that already works: `ShippingMethodAlias` and
`app/Filament/Pages/UnmappedShippingReferences.php`, which groups unresolved references by
string and client with counts and assigns them one at a time.

Per ADR-0003 decision 2, promotion creates canonical identities **deliberately or not at all**:
a human either selects an existing `CarrierService`, or explicitly authors the `Carrier` and
`CarrierService` rows. Discovery never creates either by itself. An offer nobody promotes stays
permanently human-selectable rather than nagging from a queue.

## Acceptance criteria

- [ ] An observed service can be aliased to an existing `CarrierService`
- [ ] An observed service for an unknown carrier (OnTrac) can be promoted by explicitly
      authoring the `Carrier` and `CarrierService`, as a deliberate act
- [ ] Nothing creates a `Carrier` or `CarrierService` automatically from a `getRates` response
- [ ] The page groups unmapped observations by source with counts, mirroring
      `UnmappedShippingReferences`
- [ ] An observation left unmapped indefinitely causes no errors and no nagging

## Blocked by

- `02-specify-observation-and-offer-stores`
