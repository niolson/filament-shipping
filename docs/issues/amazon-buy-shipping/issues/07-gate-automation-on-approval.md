# Exclude unapproved services from every automated path

Status: done

Repo: `polybag`

## Problem

ADR-0003 decision 4 splits on *who is choosing*. A packer picking an unfamiliar service off a
rate list has seen the price and taken responsibility. An auto-ship rule picking it at 03:00
has not.

This is the safety mechanism that makes discovery acceptable at all: without it, a newly
discovered service could win cheapest-wins unattended, on an account nobody approved it for.

## What to build

Unapproved services stay **selectable by a human on the Ship page** and are excluded from:

- `RateSelector::selectBest()`
- shipping rules / pre-selected rates
- batch ship
- auto-ship

## Acceptance criteria

- [x] An unapproved service appears in the Ship page rate list and can be chosen deliberately
- [x] The same service is never returned by `selectBest()`
- [x] Batch ship and auto-ship skip it, and say why rather than failing silently
- [x] Approving it makes it eligible everywhere without a code change
- [x] `RateSelectorTest` covers the exclusion

## Blocked by

- `06-approval-scoping`

## What shipped

One identity carried on the rate, one selection method, and one call site for every
unattended path.

- **`ObservedServiceIdentity`** — `(source, environment, external carrier id, external
  service id)` riding on `RateResponse`. This is the answer to what `06` left open: the
  identity reaches the gate from the *rate*, not from the offer and not from rate metadata.
- **`RateSelector::selectForAutomation()`** — the selection with its refusals kept.
  `selectBest()` is now a thin call to it that returns the rate alone, so the ADR's sentence
  "the same service is never returned by `selectBest()`" is true by construction rather than
  by every caller remembering a filter.
- **`UnattendedRateSelection`** — what may be bought, and what was withheld, out of one pass.
- **`EloquentPackageShippingWorkflow::selectedRateForAutoShip()`** rewritten to go through it,
  and to report the refusal as *No Approved Rates* with the services named.

Decisions the issue left open:

**`selectBest()` had no production caller, so gating it alone would have shipped nothing.**
The unattended choice was actually being read off `prepareRates()` — the *attended* view —
as `selectedRateIndex`, which is a default highlight for a packer, not a decision. Ticking
this issue's second criterion without noticing that would have left auto-ship and batch ship
exactly as open as they were. So auto-ship no longer builds the attended options at all: it
quotes, applies the rule exclusions, and calls `selectForAutomation()`. The two paths now
differ in what they are for rather than by a subscript.

That also fixed a smaller conflation on the way past: auto-ship evaluated shipping rules with
the package (weight conditions read the real parcel) and then called `prepareRates()`, which
evaluated them again without it. There is one evaluation now, the package-aware one.

**The client is a required argument on `selectBest()`, with no default.** Same reasoning as
`06`'s environment: an approval is consent from whoever is billed, and a parameter that fills
itself in is how one client's authorization comes to be spent on another client's parcel.
Null is accepted and denies every discovered service — a package with no client is a caller
that has lost track of whose money this is, which is the gate's own rule one level up.

**A rate naming no observed service passes untouched.** Approval governs *discovered*
services. Gating the seeded catalog on it would stop an install that has approved nothing
from buying anything, which is the opposite of what deny-by-default is supposed to mean here:
behaves exactly as it did before discovery existed. The consequence worth stating plainly is
that **`03` must stamp the identity on every rate the Amazon adapter returns** — a rate that
arrives without one is indistinguishable from an authored `CarrierService`, and would sail
through. The docblock on `RateResponse::$observedService` says so at the point where it would
be forgotten, and `RateResponseTest` pins the round trip so a serialization change cannot
drop it silently — that would be fail-open in the one place that must not be.

**The identity round-trips through browser state; the offer still does not.** It is Amazon's
own public carrier and service ids, which the page already shows as a carrier and a service
name, and nothing reads it back off the browser to decide anything: `ship()` rebuilds carrier,
service, price and metadata from the stored offer, and automation only ever sees rates that
came straight from a quote. Dropping it from `toArray()` would have kept `RateResponseTest`'s
"carries nothing that could buy a label" list shorter at the cost of a lossy round trip, which
is the fail-open direction.

**One query per (source, environment), not one per rate.** An Amazon `getRates` returns
several eligible offers at once and this runs per package on the batch-ship path. A rate list
with no discovered services — every install today — asks the database nothing at all, which a
test asserts against an empty query log.

**Sandbox and production are compared as one key.** `ServiceApprovalGate::approvedServiceKeys()`
returns keys with no environment in them, because the query it came from fixed one. Matching on
those alone would let a sandbox approval satisfy a production rate the moment both appear in
one list, so `ObservedServiceIdentity::approvalKey()` puts the environment back on the front of
the key before the comparison.

**A rule's pre-selected rate goes through the same gate rather than around it.** A shipping
rule is automation choosing on a packer's behalf, which is the side of ADR-0003 decision 4's
split that may not spend on an unapproved service. Today a rule names a `CarrierService` and
resolves through a direct adapter, so nothing it produces carries an identity and nothing
changes; the hook is there so that stops being true when `03` lands rather than being
remembered then.

**Batch ship reports per item, not at validation.** `validateShipmentsForBatch()` runs before
any package exists, and approval is a question about a *quoted* service — so a batch still
accepts the shipment and the refusal arrives on the `LabelBatchItem` with the service named.
Failing it earlier would mean quoting during validation, which is a carrier call per shipment
for an answer that may not matter.

Carried to `03`:

- Stamp `observedService` on every rate the Amazon adapter returns. It is the only thing that
  distinguishes a discovered service from an authored one at selection time.
- `EloquentPackageShippingWorkflow::selectedRateIndex()` still matches a pre-selected rate on
  `carrier` + `serviceCode`, which `02` already flagged. It now only affects the Ship page's
  default highlight, since auto-ship no longer reads it.
