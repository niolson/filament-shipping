# Shipping spend reports silently understate Shopify Shipping postage

Status: needs-triage

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

## Options

- **Count the packages whose cost is unknown** and show that alongside the total, e.g.
  *"$1,240.18 across 412 packages — 38 Shopify labels not included"*. Honest, cheap, no
  new data.
- **Exclude them from the average-cost-per-package** derivation, which is skewed harder
  than the total: the packages are in the denominator with nothing in the numerator.
- Feed real numbers in from `05`, if that gets built. Even then the granularity is
  per-order, so the caveat does not fully go away.

Pick one before Shopify Shipping carries meaningful volume — the numbers are wrong
quietly, which is the kind that gets believed.

## Comments
