# Specify the observation and offer stores from the `01` findings

Status: ready-for-agent

Repo: `polybag`

## Problem

ADR-0003 requires two stores with different lifecycles, and deliberately does not specify
their shape — "build only the observation and approval structure the returned data proves
necessary."

- An **offer** is ephemeral and package-specific: price, promise, `requestToken`, `rateId`,
  expiry. `rate_quotes` approximates this but is an audit log on a 60-day purge and must not
  be repurposed as an authoritative purchase context.
- An **observed service** is durable: `(source, environment, marketplace, carrierId,
  serviceId)` with first/last seen and mapping state. It deduplicates identities and must not
  retain purchase tokens indefinitely.

`01` has returned, so this is specifiable now. Captured responses are in
`.scratch/amazon-shipping-v2/` in the app repo. What they establish: `rateId` and
`requestToken` are separate opaque strings, no expiry is returned so it must be tracked from
request time, `availableValueAddedServiceGroups` and `supportedDocumentSpecifications` vary
per rate, and identity needs `(source, environment, marketplace, carrierId, serviceId)` — the
production run and the sandbox returned disjoint carrier sets for the same channel.

## What to build

The two stores, plus the offer lifecycle ADR-0002 decision 4 requires: opaque identifier,
binding to both the package and the postage-source instance, expiry, atomic consumption so
one offer cannot be spent twice, and idempotent recovery so a purchase that succeeded at
Amazon but failed on our side is found rather than repeated.

The browser holds only the opaque offer identifier. `requestToken`, `rateId`, source identity,
environment and expiry stay server-side — `RateResponse` round-trips through Livewire today,
and that is not where purchase authority belongs.

## Acceptance criteria

- [ ] Offer and observed-service stores are separate, with separate retention
- [ ] An offer cannot be consumed twice
- [ ] An expired offer fails closed with a re-quote path, not a silent purchase
- [ ] No purchase token is reachable from browser state
- [ ] Observed services deduplicate on `(source, environment, marketplace, carrierId, serviceId)`

## Blocked by

- `01-run-getrates-and-record-what-comes-back`
