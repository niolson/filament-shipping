# Scope service approval to postage source, client and environment

Status: ready-for-agent

Repo: `polybag`

## Problem

ADR-0003 decision 3. Approval is what lets automation spend money on a service, so its scope
has to be exact.

**Environment is the part that bites.** Amazon's sandbox and production identifiers differ —
and worse than differ: the sandbox run returned only `AMZN_US` / `std-us-swa-mfn`, while
production for the same channel returned OnTrac, UPS and USPS and no Amazon Shipping at all.
An approval earned against sandbox identifiers must never authorize a production purchase.

## What to build

Approval scoped to `(postage source, client, environment)`, checked before any automated
selection.

Normalization is a precondition of approval, not a parallel track — you cannot approve what
has not been named. See `05`.

## Acceptance criteria

- [ ] Approval is recorded per postage source, per client, per environment
- [ ] A sandbox approval does not authorize production spending, and vice versa
- [ ] An unapproved service cannot be reached by any automated path
- [ ] Approving requires the service to have been normalized first
- [ ] Revoking approval takes effect without needing a re-quote

## Blocked by

- `05-service-aliasing-and-mapping-page`
