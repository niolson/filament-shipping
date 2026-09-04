# Recover Shopify Shipping postage costs from balance transactions

Status: needs-triage

Repo: `polybag`

## Problem

Nothing in the label purchase reports a price, so `packages.cost` is null for every
Shopify Shipping label. That is deliberate — see the PRD — but it means postage spend on
these packages is invisible to PolyBag.

## The one route that exists

Verified 2026-08-31 by introspection: `ShopifyPaymentsTransactionType` includes a
**`SHIPPING_LABEL`** value, so postage charges surface as financial transactions:

```graphql
shopifyPaymentsAccount {
  balanceTransactions(first: 50) {
    nodes { id type amount { amount currencyCode } transactionDate associatedOrder { id name } }
  }
}
```

The query path is real. It is gated:

```
ACCESS_DENIED — requires `read_shopify_payments` or `read_shopify_payments_accounts`
```

## Four caveats that shape the design

1. **Shopify Payments only.** A shop billing postage another way has no such feed.
2. **New scopes**, neither currently granted. Declared scopes live in the Shopify Dev
   Dashboard, so widening them makes every connected store re-approve the app — this
   inherits that whole re-consent problem.
3. **It links to an order, not a label.** An order with a voided-then-rebought label
   produces several `SHIPPING_LABEL` transactions with nothing distinguishing them.
   Matching is heuristic — order plus timestamp — not exact.
4. **It settles later**, so cost arrives well after the package ships. This can never be
   shown to a packer at ship time.

## Shape if built

A periodic job pulling `SHIPPING_LABEL` transactions and attaching them to shipments at
**order granularity**, surfaced as a cost-reconciliation report kept *separate* from
`packages.cost`.

Do not backfill `packages.cost` from it. That column is exact for carrier-account labels
and populating it with an order-level approximation would quietly corrupt a number other
reports treat as precise. See `08`.

## Worth it when

Postage spend needs to land in client billing. Not worth it merely to compare Shopify
Shipping against your own USPS account — the Shopify admin already shows that.

## Comments
