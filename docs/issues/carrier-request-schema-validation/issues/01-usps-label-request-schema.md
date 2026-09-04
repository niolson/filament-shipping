# Validate USPS label requests against a hand-written schema

Status: ready-for-agent

Repo: `polybag`

## Problem

Nothing checks the shape of the USPS label body we send. The assertions in
`tests/Unit/Services/Carriers/UspsAdapterTest.php` compare against hand-written expected
arrays, which were written at the same time as the code that builds the body — so a
wrong shape frozen into both passes CI and fails at the workstation.

We have already taken this hit. That file contains a test named *"does not surface a
schema validation dump to the packer"*, which exists because USPS rejected a malformed
body with `OASValidation ... [Path '/toAddress'] Instance failed to match all required
schemas`. USPS validates against `labels-v3.yaml` server-side; we found out in
production and handled the symptom.

## Constraint — read before starting

**Do not vendor or transcribe `labels-v3.yaml`, and do not copy text from
developers.usps.com.** USPS's terms bar reproduction without written permission and bar
derivative works. See `../PRD.md`.

Write the schema by reading **our own code** — `UspsAdapter::createShipment()` and the
builders it calls (`buildDomesticAddress()`, `buildInternationalAddress()`,
`buildCustomsForm()`, `buildCustomerReference()`) — and describe the payload we
construct. Field *names* are facts about our own outbound request; the deliverable is a
description of our code's output, not a copy of USPS's document.

## Expected behavior

Add `tests/Fixtures/Schemas/uspsLabel.json` — a hand-written JSON Schema (draft-4
compatible, matching what `justinrainbow/json-schema` validates) covering the body built
for `App\Http\Integrations\USPS\Requests\Label` (`POST /labels/v3/label`).

Scope it to what we actually send:

- Required top-level fields, and required fields on `toAddress` / `fromAddress`
- Types — several USPS numeric-looking fields are strings; get this right, it is the
  most likely real bug class
- The customs block for `InternationalLabel`, if covering that in the same pass

Then wire `assertMatchesApiSchema($body, '<SchemaName>', 'uspsLabel')` into the existing
`Saloon::assertSent(function (Label $request) ...)` closures, the way
`UpsAdapterTest.php` does for `SHIPRequestWrapper`. Cover at least: a domestic label, a
label with special services, and an international label.

`InternationalLabel` (`POST /international-labels/v3/international-label`) has a
different body — either a second schema in the same document or a follow-up issue,
implementer's call.

## Test notes

- The helper lives in `tests/Pest.php`; it resolves `#/definitions/<name>` or
  `#/components/schemas/<name>` automatically, so either layout works.
- Add guard tests alongside `tests/Unit/Integrations/SpApiSchemaValidationTest.php`. A
  schema that silently validates nothing is worse than none — that file exists precisely
  to catch a `$ref` that stops resolving.
- **Prove it bites.** Temporarily break a field in `UspsAdapter` (rename a required key,
  or send a number where a string belongs), confirm the test fails with a useful message,
  then revert. Both prior PRs did this and both found something.
- Note `createUspsAccount()` in `tests/Pest.php` seeds `crid` / `mid`. The UPS work found
  the equivalent UPS fixture used a 12-character account number against a spec requiring
  exactly 6 — worth checking whether the USPS placeholders are realistic before assuming
  a failure is a code bug.

## Comments
