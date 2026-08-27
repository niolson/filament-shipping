# Vendored SP-API schemas

Amazon's own OpenAPI models, kept here so the test suite can assert that request
bodies we build actually conform to the published spec — not merely to a golden
array we wrote by hand at the same time as the code.

| File | Source | License |
|---|---|---|
| `ordersV0.json` | [`amzn/selling-partner-api-models`](https://github.com/amzn/selling-partner-api-models) — `models/orders-api-model/ordersV0.json` | Apache-2.0 |

Refresh with:

```bash
curl -sL -o tests/Fixtures/Schemas/ordersV0.json \
  https://raw.githubusercontent.com/amzn/selling-partner-api-models/main/models/orders-api-model/ordersV0.json
```

These are Swagger 2.0 documents. The schema objects under `definitions` are a
draft-4 subset, which is what `justinrainbow/json-schema` validates against.

## Why `ordersV0` and not `orders_2026-01-01`

Because the Orders API is split by direction, not superseded wholesale.
`orders_2026-01-01.json` declares exactly two operations — `searchOrders` and
`getOrder`, both `GET` — and contains no shipment-confirmation schema at all. It
is read-only.

Amazon marked all six v0 *read* operations `deprecated` in favor of that dated
version, but left the *writes* on v0. `confirmShipment` is not deprecated and has
no newer version, so `ordersV0.json` is the only schema that describes the
package tracking write-back payload.

That's why `SearchOrders` targets `/orders/2026-01-01/orders` while
`ConfirmShipment` targets `/orders/v0/orders/{orderId}/shipmentConfirmation` —
the mismatch is Amazon's, and it's intentional on our side.

If we ever schema-check the *read* side (today those responses are mocked), that
needs `orders_2026-01-01.json` vendored alongside this file, and
`assertMatchesSpApiSchema()` takes the document name as its third argument.

Note the spec is looser than Amazon's real validation in places — for example
`packageReferenceId` is typed `string`, with "only positive numeric values are
supported" living in the prose description. Passing this check is a floor, not a
guarantee the live API will accept the request.
