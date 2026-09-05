# Shipping spend reports silently understate Shopify Shipping postage

Status: done — 2026-09-05

Repo: `polybag`

## Problem

`packages.cost` is null for every Shopify Shipping label, because Shopify reports no
price. `AggregateShippingStats` sums cost with:

```sql
COALESCE(SUM(p.cost), 0)
```

So each Shopify package contributes **$0.00** to `total_cost` in
`daily_shipping_stats`. The figure is not flagged, estimated, or absent — it reads as a
complete total and is not one. A tenant shipping heavily through Shopify sees postage
spend that is wrong by however much they actually spent, with nothing indicating it.

`total_cost` is the number at risk. `package_count` and `total_weight` stay correct.

## Why null is still the right storage

Deliberate, and worth not undoing: a fabricated `0.00` on the package would read as a
*free label* everywhere cost appears — the package list, the detail page, exports — and
would be indistinguishable from a genuine zero. Null reads as "unknown", which is true.
The package detail page already renders it as *"Billed by Shopify — not reported through
the API"*.

The defect is in the reporting layer, not the storage.

## Decision

The two options this issue originally listed — disclose the gap on the total, and drop
unpriced packages out of the average — are not alternatives. They fix **different
numbers**: the first fixes what the sum claims to be, the second fixes arithmetic. And
both need the same missing datum, so there is no saving in picking one.

The datum is the thing to decide, and it needs schema. `daily_shipping_stats` is a
pre-aggregated rollup; once a day is aggregated, *how many of these packages had a null
cost* is unrecoverable from it, and every consumer reads the rollup rather than
`packages`. So: **add `costed_package_count` to the rollup**, then do both.

Two alternatives were rejected:

- **Keying off `carrier = 'Shopify'`.** Hardcodes one seller into the reporting layer,
  and null cost is not unique to Shopify — a manual ship or a failed cost write produces
  the same hole. `costed_package_count` describes the condition rather than one cause of
  it.
- **Waiting for `05`.** Independent, and does not remove the need: `05` is gated behind
  Shopify Payments scopes that force every connected store to re-consent, matches
  heuristically, and covers only Shopify Payments shops. Packages stay unpriced either
  way.

## What was built

- `daily_shipping_stats.costed_package_count`, nullable. Null means "never computed for
  this row", and readers fall back to `package_count` — the behaviour that predates the
  column. `default(0)` was avoided for the same reason `0.00` was avoided on the package:
  it would assert every package in the row was unpriced.
- A backfill migration recomputes it for existing rows, since the nightly schedule only
  rebuilds yesterday and today and history would otherwise stay null forever. Grouping
  keys are matched with COALESCE sentinels because five of the six are nullable. A rollup
  row with no matching packages is left null rather than set to 0.
- `VolumeReport` divides by priced packages, reports an unknown average rather than zero
  when nothing in a group was priced, and prints *"excludes N with no reported cost"*
  under the total and *"over N priced"* under the average.
- `CostPerPackageTrend` uses the same divisor and gaps the series on a day where nothing
  reported a cost, instead of dipping to zero. Its description names the excluded count.
- `StatsOverview` appends the excluded count to the weekly cost stat.

## Acceptance criteria

- [x] The rollup records how many of its packages reported a cost
- [x] Existing rows are backfilled rather than left reading as fully priced
- [x] Cost-per-package divides by priced packages only, in both the report and the widget
- [x] An all-unpriced group reports an unknown average, not `$0.00`
- [x] Every surface that shows a cost total says how many packages it left out
- [x] A rollup row from before the column still reads exactly as it did

## Comments

- Code review, two fixes after the fact:
  - The backfill matched grouping keys with COALESCE sentinels, which collapsed
    `service = ''` into `service IS NULL`. Both are real states on a shipped package —
    `ServiceEvidenceBackfillTest` covers the empty one — and GROUP BY keeps them apart,
    so each of the two rollup rows was handed the other's packages; with a 3/1 split the
    single-package row came back with a costed count of 3. Every nullable key now matches
    by equality OR both sides being null. `carrier` had the identical flaw and was fixed
    with it. The integer keys were sound (no real foreign key is 0) but were made uniform
    rather than left resting on that.
  - `StatsOverview` disclosed *this* week's unpriced packages while still comparing
    against last week's total, which has the same hole. An understated last week inflates
    the percentage and looks no different from a measured one — and it carried a colour
    and a trend arrow, making it the strongest claim on the card. The change is now
    withheld whenever either week is incomplete, and the description says which.
- Spun out: `12-client-billing-invoices-unpriced-postage-as-zero`. `ClientBillingReport`
  has the identical `COALESCE(SUM(p.cost), 0)` on its own query path, and there the
  number is invoiced rather than displayed. Not fixed here — it is a billing decision,
  not a reporting one.
- The two widget cache payloads changed shape, so their keys are versioned
  (`widget:stats_overview:v2`, `widget:cost_trend:v2`) and `InvalidateDashboardCache`
  follows. Without that, a cache entry written by the previous deploy is read back into
  code expecting the new shape.
- `stats:aggregate` had no test coverage before this, for a reason worth recording:
  SQLite has no date type, so a date-cast column round-trips as `"Y-m-d H:i:s"` and the
  command's `BETWEEN` on plain Y-m-d bounds matches nothing under the test database.
  `DailyShippingStat` already carries a mutator working around exactly this for its own
  `date` column. The new tests normalise `packages.ship_date` to what MySQL would hold;
  `packages` was left alone, since changing its casting is a wider change than this issue
  covers.
