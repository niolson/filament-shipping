# Find out which FedEx sandbox rate requests come back intact

Status: ready-for-human
Category: research
Type: manual
Repo: **`polybag`**

## Parent

`docs/issues/fedex-sandbox-rate-testing/PRD.md`

## Problem

`FedexAdapter::buildRateApiRequest()` throws away the caller's rate request in sandbox
mode and sends FedEx's US-domestic docs example instead. The comment above it gives the
reason:

> The FedEx sandbox returns truncated (unparseable) JSON for most request shapes. The
> example payload from the FedEx developer docs is the one known request that produces a
> valid, complete response from the sandbox API.

"Most request shapes" is the whole difficulty. Nobody has characterised which shapes fail
or why, so the workaround had to be total — replace everything — and a total replacement
makes international rating unreachable in sandbox, since the destination is overwritten
before the request is sent.

This cannot be reasoned out from the code. It needs real requests to the sandbox and a
record of what comes back.

`ready-for-human` because it needs FedEx sandbox credentials on a carrier account, and
because `sandbox_mode` is a shared cross-carrier toggle that should be flipped by hand —
turning it on moves UPS, USPS and Amazon at the same time.

## Before starting

- Rate quotes are read-only. No postage is bought and nothing is spent.
- The two international rate fixtures already in the repo are FedEx's own documented test
  cases. Start from those rather than composing new payloads — a request the sandbox was
  built to accept is the one useful control.
- `FedexTestCaseRunner` sends fixtures straight through `FedexConnector`, so it bypasses
  `buildRateApiRequest()` entirely. That is the harness for this work. It currently has no
  rate branch, so getting a rate fixture to execute is step one and is small.
- Capture raw payloads to `.scratch/` — gitignored, and rate responses carry addresses.

## What to answer

1. **What does "truncated" actually look like?** Capture a failing response verbatim.
   Is it valid JSON cut short, a partial body with a 200, a content-length mismatch, or a
   gateway-level truncation? This decides whether it is detectable and retryable rather
   than something to route around.
2. **What distinguishes a request that succeeds from one that does not?** Vary one thing
   at a time from the known-good docs example: payload size, number of requested service
   codes, `rateRequestType`, presence of `requestedPackageLineItems` detail, special
   services, and the international/domestic split. The goal is a rule, not a list.
3. **Does either committed international rate fixture come back intact, sent verbatim?**
   This is the gate. If FedEx's own international test cases answer properly, the sandbox
   is usable for international rating and the adapter's blanket override is the only thing
   in the way.
4. **Does the sandbox rewrite addresses or force domestic services on a request that
   matches a canned case?** Compare what was sent against what the response describes.
5. **Can `buildRateApiRequest()`'s override be narrowed** to the shapes that actually
   fail, rather than every request? If yes, sketch the condition.

## Acceptance criteria

- [ ] `FedexTestCaseRunner` executes a rate fixture — a `Rates` branch in its request-type
      match, keyed off `requestType`, alongside the existing shipment branches
- [ ] The two international rate fixtures run verbatim against the sandbox, and their
      `supported` / `skip_reason` fields are updated to reflect what actually happened
- [ ] Raw request and response pairs captured to `.scratch/`, including at least one
      truncated response
- [ ] Questions 1–5 answered in this file's `## Comments`
- [ ] A recommendation recorded for `buildRateApiRequest()`: narrow the override, keep it
      as is, or remove it in favour of the runner path
- [ ] If the override stays, its comment is updated to say what is now known about which
      shapes fail

## Out of scope

- Restoring fabricated international rates. A mock answers whether our parsing works on a
  payload we wrote, not whether FedEx accepts the request, and the second is the question.
- Production FedEx international rating, which is unaffected by any of this — the override
  is sandbox-only.
- `tests/External/Fedex/`, which currently holds nothing but a `.gitkeep`, so
  `composer run test:fedex-reference` runs an empty suite. Worth its own issue once there
  is something reproducible to assert.

## Blocked by

None. Needs a FedEx carrier account with sandbox credentials.

## Comments

### 2026-09-05 — filed

Split out of the dead-code review in #187, which removed `getMockInternationalRates()`,
the `INTERNATIONAL_SERVICE_CODES` constant, and the two commented-out call sites that
would have returned fabricated international rates in sandbox mode. PHPStan's baseline
had been carrying entries asserting both the constant and the method were unused, so the
code had been suppressed rather than removed for some time.

The removal is not the reason this issue exists — the need it was reaching for is, and it
predates the mock. See the parent PRD for why the two layers of domestic forcing, ours
and FedEx's, are easy to mistake for one.
