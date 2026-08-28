# Vendored API schemas

Carriers' and marketplaces' own API specs, kept here so the test suite can assert that
the request bodies we build actually conform to the published contract — not merely to a
golden array we wrote by hand at the same time as the code that builds it.

| File | Source | License |
|---|---|---|
| `ordersV0.json` | [`amzn/selling-partner-api-models`](https://github.com/amzn/selling-partner-api-models) — `models/orders-api-model/ordersV0.json` | Apache-2.0 |
| `upsRating.json` | [`UPS-API/api-documentation`](https://github.com/UPS-API/api-documentation) — `Rating.yaml` | MIT |
| `upsShipping.json` | [`UPS-API/api-documentation`](https://github.com/UPS-API/api-documentation) — `Shipping.yaml` | MIT |

## Licenses and attribution

Both upstream licenses require their notice text to travel with copies, so the notices are
vendored verbatim in `licenses/` rather than named only in the table above:

| Notice | Covers |
|---|---|
| `licenses/UPS-API-api-documentation-LICENSE.txt` | `upsRating.json`, `upsShipping.json` |
| `licenses/amzn-selling-partner-api-models-LICENSE.txt` | `ordersV0.json` |
| `licenses/amzn-selling-partner-api-models-NOTICE.txt` | `ordersV0.json` |

MIT requires "the above copyright notice and this permission notice" to be included in all
copies or substantial portions; Apache-2.0 §4 requires a copy of the License and the
upstream NOTICE. All three files are byte-for-byte copies of their upstream originals.

**`ordersV0.json` is an unmodified copy.** The two UPS files are **modified copies**:
converted from YAML to JSON and passed through the cardinality fix described below. Both
transformations are mechanical and reproducible from the refresh script — no schema was
hand-edited to accommodate our request bodies.

These notices cover the vendored specs only. They say nothing about PolyBag's own license,
and nothing about the separate agreements governing *use* of either API — UPS's spec
carries an `info.termsOfService` pointing at its own service agreement.

Refresh the Amazon spec with:

```bash
curl -sL -o tests/Fixtures/Schemas/ordersV0.json \
  https://raw.githubusercontent.com/amzn/selling-partner-api-models/main/models/orders-api-model/ordersV0.json
```

The UPS specs need a conversion step — see below.

Refresh the vendored notices alongside any spec refresh:

```bash
L=tests/Fixtures/Schemas/licenses
curl -sL -o $L/UPS-API-api-documentation-LICENSE.txt \
  https://raw.githubusercontent.com/UPS-API/api-documentation/main/LICENSE
curl -sL -o $L/amzn-selling-partner-api-models-LICENSE.txt \
  https://raw.githubusercontent.com/amzn/selling-partner-api-models/main/LICENSE
curl -sL -o $L/amzn-selling-partner-api-models-NOTICE.txt \
  https://raw.githubusercontent.com/amzn/selling-partner-api-models/main/NOTICE
```

## Why USPS and FedEx are not here

Only specs we are licensed to redistribute get vendored.

- **USPS** forbids it. The [terms](https://developers.usps.com/terms-and-conditions) say
  material "may not under any circumstances be reproduced or used without USPS's prior
  written permission", and separately bar preparing "any derivative work of" it.
- **FedEx** forbids it. The FDPLA bars derivative works (§5(n)) and disclosure of FedEx
  Technology to third parties (§5(e), §11(a)).

For those two, write a schema by hand describing the payload *we* build, read off our own
adapter code. It is our work product describing our own output, and it still catches the
failure that actually bit us — USPS rejecting a label with
`[Path '/toAddress'] Instance failed to match all required schemas`.

## The UPS specs need a conversion step

UPS publishes YAML generated from their XML schemas, and the generator mistranslates XML
cardinality into JSON Schema's `maximum`, which is a *numeric* bound. In `Rating.yaml`
alone that is 429 occurrences on `string` and `object` types, where it means nothing, and
a handful on arrays, where it means `maxItems`. Validating against the raw file produces
hundreds of false failures. `Shipping.yaml` also contains tab characters inside
description strings that stop a strict YAML parser.

Refresh both with:

```bash
python3 - <<'EOF'
import urllib.request, yaml, json

def fix(node):
    if isinstance(node, dict):
        if 'maximum' in node:
            t = node.get('type')
            if t == 'array':
                node['maxItems'] = node.pop('maximum')
            elif t in ('string', 'object'):
                node.pop('maximum')
        if 'minimum' in node and node.get('type') in ('string', 'object'):
            node.pop('minimum')
        return {k: fix(v) for k, v in node.items()}
    if isinstance(node, list):
        return [fix(v) for v in node]
    return node

base = 'https://raw.githubusercontent.com/UPS-API/api-documentation/main/'
for src, out in [('Rating.yaml', 'upsRating.json'), ('Shipping.yaml', 'upsShipping.json')]:
    raw = urllib.request.urlopen(base + src).read().decode('utf-8')
    raw = raw.replace('\r\n', '\n').replace('\t', ' ')
    json.dump(fix(yaml.safe_load(raw)), open('tests/Fixtures/Schemas/' + out, 'w'), indent=1)
EOF
```

Converting to JSON at vendor time is deliberate: it keeps the fixtures in one format and
avoids depending on `symfony/yaml` at test time, which is only present transitively via
`laravel/roster` and `laravel/sail`.

## Format

Amazon ships Swagger 2.0, UPS ships OpenAPI 3 — `assertMatchesApiSchema()` finds the
schema under `definitions` or `components/schemas` and builds the right pointer, so
callers just pass a name.

These are Swagger 2.0 and OpenAPI 3 documents. The schema objects under `definitions` are a
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
