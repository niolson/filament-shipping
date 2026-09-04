# Validate FedEx shipment requests against a hand-written schema

Status: ready-for-agent

Repo: `polybag`

## Problem

Same gap as `01`, for FedEx. `FedexAdapter` builds the largest request bodies in the
codebase — `createShipment()` plus `buildCustomsClearanceDetail()`,
`buildPackageSpecialServices()`, `buildCustomerReferences()`, `buildContact()`,
`buildShipmentSmartPostInfoDetail()` — and nothing validates their shape. The existing
assertions in `tests/Unit/Services/Carriers/FedexAdapterTest.php` and
`tests/Unit/Integrations/FedexConnectorTest.php` check individual fields against
hand-written expectations.

FedEx has more body-bearing request classes than any other integration in
`app/Http/Integrations/` (15), so the surface is correspondingly large.

## Constraint — read before starting

**Do not vendor or transcribe FedEx's OpenAPI specs or portal documentation.** The FDPLA
bars derivative works (§5(n)) and disclosure of FedEx Technology to third parties (§5(e),
§11(a)). See `../PRD.md`.

Write the schema from **our own code** in `FedexAdapter` and the request classes under
`app/Http/Integrations/Fedex/Requests/`.

## Expected behavior

Add `tests/Fixtures/Schemas/fedexShip.json` covering the body built for
`Fedex\Requests\CreateShipment`, and wire `assertMatchesApiSchema()` into the existing
`assertSent` closures.

Start with `CreateShipment` only. `Rates` is a reasonable follow-up but fails soft — a
malformed rate request drops FedEx from the comparison, where a malformed ship request
means no label at the bench.

Worth covering, since these are where the shape varies most:

- Domestic ground, the common path
- International with customs clearance detail
- SmartPost, which takes a different branch
- A shipment carrying special services

## Test notes

- Follow the pattern in `UpsAdapterTest.php` under "Request schema conformance".
- Add guard tests for the schema itself — see `01` and
  `tests/Unit/Integrations/SpApiSchemaValidationTest.php`.
- **Prove it bites** before calling it done: break a required field in `FedexAdapter`,
  confirm a useful failure, revert.
- `FedexAdapterTest.php` asserts on `config(['services.fedex.account_number' => 'test_account'])`
  in several places. If the schema constrains account-number length, check what FedEx
  actually requires before changing the fixture — and change only the FedEx one. UPS and
  FedEx previously shared the string `test_account` for unrelated reasons.

## Open question

The FDPLA §5 clause barring distribution of Materials for use in a **multi-carrier
system** is a broader product question than this issue, since PolyBag is one. It does not
block this work — a schema written from our own code is not FedEx Materials — but it is
worth a separate read. Noted in `../PRD.md`.

## Comments
