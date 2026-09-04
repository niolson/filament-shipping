# Exclude unapproved services from every automated path

Status: ready-for-agent

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

- [ ] An unapproved service appears in the Ship page rate list and can be chosen deliberately
- [ ] The same service is never returned by `selectBest()`
- [ ] Batch ship and auto-ship skip it, and say why rather than failing silently
- [ ] Approving it makes it eligible everywhere without a code change
- [ ] `RateSelectorTest` covers the exclusion

## Blocked by

- `06-approval-scoping`
