# Add service aliasing and a "Map Carrier Services" page

Status: done

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

- [x] An observed service can be aliased to an existing `CarrierService`
- [x] An observed service for an unknown carrier (OnTrac) can be promoted by explicitly
      authoring the `Carrier` and `CarrierService`, as a deliberate act
- [x] Nothing creates a `Carrier` or `CarrierService` automatically from a `getRates` response
- [x] The page groups unmapped observations by source with counts, mirroring
      `UnmappedShippingReferences`
- [x] An observation left unmapped indefinitely causes no errors and no nagging

## Blocked by

- `02-specify-observation-and-offer-stores`

## What shipped

One service and one page. No new table: `02` already left `observed_services.carrier_service_id`
nullable for exactly this, and the observed identity *is* the alias — adding a second table
keyed on the same five columns would only have given the two places to disagree.

- **`ObservedServiceMapper`** — `map()` aliases an identity onto an existing `CarrierService`,
  `promote()` authors a new one and aliases onto that, `unmap()` puts it back. Every method is
  reached from a button; nothing calls them from a quote.
- **`UnmappedObservedServices`** (nav: Integrations → *Map Carrier Services*) — grouped by
  source, defaulting to the unmapped filter, with per-source summaries of how many identities
  and how many sightings. Three record actions: **Assign**, **Author service**, **Unmap**.

Decisions the issue left open:

**Aliasing spans environments and marketplaces; approval will not.** The store dedupes on the
five-part identity, but a mapping covers `(source, external_carrier_id, external_service_id)`
only. ADR-0003 decision 3 scopes *approval* to an environment because sandbox and production
identifiers differ and money is at stake. A name is not an approval: if both worlds report
`USPS/USPS_GROUND_ADVANTAGE`, that is one service, and asking a human to say so twice is an
invitation for the two answers to differ. The action reports how many observations one
assignment covered rather than doing it silently.

**A mapping has to hold for rows that do not exist yet, so the recorder reads that scope too.**
Caught in review, and it was the difference between a mapping and a one-time update: an
assignment only reaches rows on file when it runs, and `ObservedServiceRecorder` inserts every
new identity with a null `carrier_service_id`. Map Ground Advantage in production today, quote
in sandbox tomorrow, and the service comes back as a fresh row nobody ever decided anything
about — silently unmapped, with no event to notice. So `insertMissing()` now looks up a mapping
for the service it is about to insert and carries it over.

That is still not discovery creating catalog rows: it copies a `carrier_service_id` a person
already chose onto another sighting of the service they chose it for, and cannot produce an
identifier nobody authored. It costs one query, and only when a quote brought back a service
never recorded before — the ordinary quote inserts nothing and never reaches it, so the query
budget the recorder is tested against is unchanged.

The scope now lives in one place, `ObservedService::scopeSameService()` (with `serviceKey()`
for the in-memory half), because the mapper and the recorder drifting apart is exactly how
services would start coming back unmapped.

**Two writers of one column, so they take one lock.** Also from review, and the direct
consequence of the fix above: the recorder now reads a mapping and then inserts a row carrying
it, which is read-then-write against a column an operator is editing from a page. Interleaved,
a mapping made in that window never reaches the new row and an unmapping in it is undone by a
value read a moment before it was withdrawn — permanently, silently, with nothing to notice it
later. Both sides now take `ObservedService::MAPPING_LOCK`, which is where the reasoning lives.

The lock is one name rather than one per service: a quote can bring back a hundred identities
at once, and the alternative to a single uncontended lock is a hundred of them. Both sides wait
rather than refuse, because each holds it for a single statement. The recorder only reaches for
it when a quote returned a service never recorded before, so the ordinary quote — every identity
already on file — never touches it at all; there is a test holding the lock across an ordinary
quote to keep that true.

`promote()` calls the unlocked `applyMapping()` rather than the public `map()`, because the
lock is not reentrant and it is already held by then.

**The alias stayed on the sighting rather than moving to its own table.** Review offered that
as the alternative, and it is the more normalized model: both defects above come from storing a
service's name on each sighting of it. It was not taken, on the grounds that the join it
implies is on three string columns, every reader — `03`, `06`, `07` — pays for it forever, and
what it buys is avoidable by two writers agreeing on one lock. That is a denormalization held
in place deliberately, and the constant's docblock says so, so the next person to add a writer
of `carrier_service_id` finds out before they have written it.

**Authoring is admin-gated; aliasing is not.** The page opens at `Manager`, like its two
siblings. But `CarrierServicePolicy` has said `Admin` for catalog rows since long before this,
and minting a `Carrier` from a mapping screen is the same act as minting one from
`CarrierServiceResource`. So *Assign* is a manager's to use and *Author service* is hidden
unless `can('create', CarrierService::class)` passes. Both new rows are already audited —
`Carrier` and `CarrierService` are both in `AuditableObserver::observe()`, so promotion shows up
in the audit log as the authorship it is.

**The carrier is authored through the select's create-option form, not by the mapper.** Passing
a saved `Carrier` into `promote()` keeps the two acts visibly separate: you create OnTrac, then
you create OnTrac Ground under it. A `promote()` that took a carrier *name* would be one call
that quietly does both, which is the shape ADR-0003 decision 2 rejects.

**The form is prefilled from the observation, and that is not the same as discovery.** Service
code and name default to what the source reported, and the carrier select defaults to a row
whose name matches. A default a human reads and submits is a deliberate act; the distinction
the ADR draws is about who decides, not about who types.

**No navigation badge.** Deliberate, and tested for. Unmapped is a valid terminal state
(decision 8), and a badge turns "valid" into "outstanding work" for something that never has to
be done. The empty state says so in as many words.

One thing carried in from outside the issue: `larastan` could not see that `User::$role` is
a `Role`, so every `role->isAtLeast()` call site in the app sat in `phpstan-baseline.neon` —
37 entries. Writing this page's `canAccess()` meant either adding the 38th or fixing it, so
a `@property Role $role` annotation on `User` went in and all 37 entries came out.
