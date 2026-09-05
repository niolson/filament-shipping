# Issue index

Conventions live in `../agents/issue-tracker.md`; the `Status:` vocabulary lives in
`../agents/triage-labels.md`. This file is the at-a-glance view of what is open.

**Resolved issues stay where they are.** `done` means "implemented and verified; kept
for history" — the implementation record in the file's `## Comments` section is the
point of keeping it. There is no archive directory, and adding one would give agents a
second place to look.

## Open work

| Directory | Open | State |
|---|---|---|
| [shopify-shipping-carrier](shopify-shipping-carrier/) | 8 of 10 | Adapter shipped 2026-08-31. `01` gates the purchase-path work and is `ready-for-human` — a label must be bought in the Shopify admin before the API will sell one. `09` **done** 2026-09-04 — Shopify is now a priceless offer beside the rates, behind a per-client opt-in and a confirmation, and out of every automated path; `10` shipped with `postage-source-split/11`. |
| [amazon-buy-shipping](amazon-buy-shipping/) | 1 of 8 | Implements ADR-0003 (Accepted 2026-09-02). `01` **done** — production `getRates` returned 6 offers across OnTrac/UPS/USPS, so the multi-carrier premise holds. `04` **done** 2026-09-04, which unblocks Amazon sandbox work generally. `02` **done** 2026-09-04 — the offer and observed-service stores, which unblocks `03` and `05` and the deferred quote/purchase half of `postage-source-split/08`. `05` **done** 2026-09-05 — observed services can be aliased or promoted from *Map Carrier Services*, so OnTrac has a home before `03` starts returning it. `06` **done** 2026-09-05 — approval is a row per source/client/environment, deny by default, granted from the same page. `07` **done** 2026-09-05 — auto-ship, batch ship and pre-selected rates all select through `RateSelector::selectForAutomation()`, which withholds an unapproved discovered service and names it; the Ship page still lists it for a person to choose. `03` **done** 2026-09-05 — the adapter quotes, buys, voids and tracks through Amazon, keeping the real carrier per offer and stamping the observed-service identity on every rate; quoting and purchasing now dispatch by the offer's postage source, which discharges the deferral in `postage-source-split/08`. One acceptance criterion is deliberately unticked: nothing has been run against a live order yet. `08` is a `needs-triage` design question, and post-quote filtering — the option it names — is what shipped. |
| [data-source-improvements](data-source-improvements/) | 4 of 7 | All `ready-for-agent`. Premises re-verified against `main` 2026-08-22. |
| [carrier-request-schema-validation](carrier-request-schema-validation/) | 2 of 2 | Both `ready-for-agent`. Extends the pattern from #144/#145 to USPS and FedEx, whose specs cannot be vendored. |
| [fedex-sandbox-rate-testing](fedex-sandbox-rate-testing/) | 1 of 1 | `ready-for-human`. The FedEx sandbox answers most rate request shapes with truncated JSON, so `FedexAdapter::buildRateApiRequest()` replaces every sandbox rate request with a canned domestic one — which makes international rating unreachable there. Needs real sandbox requests to characterise before anything can be decided. Two international rate fixtures are already committed and skipped. |
| [nginx-upstream-resolution](nginx-upstream-resolution/) | 1 of 1 | `ready-for-agent`. A one-line `docker/nginx.conf` fix that affects on-prem installs as much as any hosted deployment. |
| [postage-source-split](postage-source-split/) | 1 of 13 | Implements ADR-0002 (Accepted 2026-09-01). `01`–`12` all **done**, shipped 2026-09-02 to 09-04 as #155–#171. Only `13` is open, a `needs-triage` presentation question left behind by `07`. |
| [tech-debt](tech-debt/) | Phases 2–4 | A plan with checkboxes, not issue files — no `Status:` line, so it does not appear in the grep below. |

## Closed

| Directory | Issues | Closed |
|---|---|---|
| [special-services](special-services/) | 7 of 7 `done` | Shipped 2026-07-09, #72; review gate closed 2026-08-22. The four `*-api-reference.md` files and the cross-carrier report are `reference` — vendor capability tables worth keeping. |

## The grep

```bash
grep -rn '^Status:' --include='*.md' docs/issues \
  | grep -Ev 'Status: (done|reference|closed|wontfix)'
```

`reference` excludes PRDs and background findings, which are framing rather than work
items. Keep new `Status:` lines to the vocabulary in `triage-labels.md` so this keeps
working — a status line that opens with anything else shows up as open work.

## Work tracked elsewhere

Deployment and hosting work for the instances we operate ourselves is tracked privately
alongside that tooling, for the reason given in `docs/self-hosting.md`: none of it is
needed to run PolyBag. Two directories here are the app half of a cluster whose other
half is private — `nginx-upstream-resolution` (the deploy-side follow-on) and
`shopify-shipping-carrier` (the Dev Dashboard scope rollout). Each says so where it
matters. Nothing open in this index is blocked on anything in that repo.
