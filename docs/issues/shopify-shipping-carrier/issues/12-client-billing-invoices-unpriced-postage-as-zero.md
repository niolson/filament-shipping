# Client billing invoices unpriced postage as $0.00

Status: needs-triage

Repo: `polybag`

## Problem

`ClientBillingReport` builds its postage figure the same way the dashboard rollup did
before `08`:

```php
DB::raw('COALESCE(SUM(p.cost), 0) as postage'),
```

`app/Filament/Pages/Reports/ClientBillingReport.php`, `buildPackageSubquery()`. That
`postage` is not display-only — it is the first term of `line_total`:

```php
'pkg.postage + pkg.package_count * '.$labelFee.' + ...'
```

So a package whose seller reports no price bills the client **$0.00 of postage**, while
the label fee, pick fees, materials and surcharges on the same line all charge normally.
The line looks complete. Nothing on it says postage is missing.

This is the same root cause as `08` — `packages.cost` is null when the seller reports no
price — but a different query path (it reads `packages` directly, not
`daily_shipping_stats`) and a materially different consequence. `08` produced a
misleading dashboard number. This produces an invoice that is wrong in the client's
favour, by however much the postage actually cost.

Shopify Shipping is the case that prompted this, but it is not the only one: any package
with a null cost bills the same way.

## Why it wasn't fixed alongside `08`

`08` was a reporting decision — how to describe a number that is knowably incomplete.
This is a billing decision, and the options are not the same ones:

- **Show the gap and let a human resolve it.** Flag lines with unpriced postage in the
  report so nobody exports an invoice without seeing them. Cheapest, and consistent with
  how `08` treats the dashboard. Does not answer what to actually charge.
- **Exclude those lines from the invoice** until postage is known, rather than billing
  them at a wrong total.
- **Bill a per-client fallback postage rate**, reconciled later. Charges something, but
  invents a number — which is the thing `08` explicitly refused to do to
  `packages.cost`. Whether that objection carries over to a billing rate a client has
  agreed to is the question.
- **Wait for `05`** to supply real costs. Same caveats as in `08`: Shopify Payments only,
  heuristic matching, and a re-consent problem. It narrows the gap without closing it.

Needs a decision from whoever owns billing, not a reporting default.

## Blocked by

Nothing. `08` is done and did not change this path.
