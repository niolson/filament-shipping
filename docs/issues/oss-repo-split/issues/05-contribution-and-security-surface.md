# Add the contribution and security-reporting surface

Status: done
Category: enhancement
Type: AFK

## Parent

`docs/issues/oss-repo-split/PRD.md`

## What to build

The public repo has no `CONTRIBUTING.md`, no `CODE_OF_CONDUCT.md`, no `SECURITY.md`,
and no issue or PR templates. Add them.

**`SECURITY.md` is the one that actually matters and should land first.** This app
stores carrier API credentials, runs address validation against real addresses, and
handles OAuth secrets. Right now someone who finds a vulnerability has no private way
to report it, and the plausible fallback is a public GitHub issue. It needs:

- A private disclosure address, and **GitHub private vulnerability reporting enabled**
  on the repo (Settings → Code security). The file alone is not enough.
- A stated response-time expectation, kept modest enough to actually meet.
- Scope: which parts are in scope, and that the hosted `*.polybag.app` service is
  reported the same way.
- An explicit note that the licence is BSL (see issue 06) so reporters know what they
  are working on.

**`CONTRIBUTING.md`** should cover the real local loop, which already exists and works:
`composer run setup`, `composer run dev`, `composer run test`, `vendor/bin/pint`, and
the Pest conventions. Two things need explaining rather than just listing:

- `.githooks/pre-push` blocks pushes on `phpstan`, `composer audit`, **and**
  `npm audit --audit-level=high`. A dependency advisory published upstream will block
  an outside contributor's push on code unrelated to it. Either make the audit steps
  advisory (warn, don't exit non-zero) and keep phpstan blocking, or document how to
  skip. Recommend the former — the CI security-audit workflow already covers audits on
  every push and pull request, so the hook is redundant as a gate.
- Contributors need to know a CLA/DCO expectation up front given the BSL and any future
  relicensing. Decide which, and say so. This is a licensing-adjacent call — coordinate
  with issue 06.

**Templates** — a bug report template that asks for carrier, deployment mode
(standalone / shared / external), and browser (the WebHID scale path is Chrome/Edge
only over HTTPS), plus a short PR template.

## Acceptance criteria

- [x] `SECURITY.md` exists with a private reporting path, scope, and response expectation
- [x] GitHub private vulnerability reporting is **enabled** on the repo — verified in settings, not just documented
- [x] `CONTRIBUTING.md` covers setup, tests, Pint, PHPStan, and the hook behaviour
- [x] `.githooks/pre-push` no longer blocks a push solely because of an upstream dependency advisory, or the escape hatch is documented
- [x] A CLA or DCO decision is recorded and reflected in `CONTRIBUTING.md`
- [x] `CODE_OF_CONDUCT.md` exists with a working contact address (`conduct@polybag.app`)
- [x] Issue and PR templates exist under `.github/`
- [x] Nothing added here implies the project is under an OSI licence (see issue 06)

## Blocked by

None - can start immediately, though the CLA/DCO line should agree with issue 06.

## Comments

### 2026-08-18 — Implemented

**Decisions taken** (both were open questions in the brief):

- **CLA, not DCO.** PolyBag is dual-licensed in practice — BSL 1.1 for everyone,
  commercial terms sold separately by POLYBAG.APP LLC. A DCO sign-off certifies origin
  but grants no right to sublicense a contribution commercially, which would make any
  commercial licence covering contributed code legally murky, and would complicate the
  March 2030 Apache-2.0 conversion. `CONTRIBUTING.md` states the CLA requirement and
  says a maintainer emails it on first PR — no bot, no click-through, which is the right
  weight at zero contribution volume. Agrees with issue 06 (licence unchanged).
- **Contacts:** `security@polybag.app` for vulnerabilities, `conduct@polybag.app` for
  the Code of Conduct — both confirmed to route. `license@polybag.app` (already in
  `LICENSE`) is reused for commercial terms.

**Shipped**

- `SECURITY.md` — two private channels (GitHub advisory + email), a response table
  (5 business days to acknowledge, 10 to assess, 90 to fix or produce a dated plan),
  explicit in/out of scope including `*.polybag.app` and the rules for testing against
  it, "only `main` is supported" (there are no tags), and a BSL note stating the licence
  will not be used against good-faith researchers.
- **GitHub private vulnerability reporting: enabled and verified.**
  `PUT /repos/niolson/polybag/private-vulnerability-reporting` returned 204, and
  `GET` now returns `{"enabled":true}`. It was `false` before.
- `CONTRIBUTING.md` — licence posture up front, the CLA, setup (`composer run setup`,
  `.env.local.example` for non-Docker work, `FAKE_CARRIERS=true`), the loop, Pest
  conventions (`RefreshDatabase` is global in `tests/Pest.php`; do not regenerate the
  PHPStan baseline), hook behaviour, PR conventions matching the repo's actual commit
  style, and notes on carrier adapters, hardware JS, and dependencies.
- `CODE_OF_CONDUCT.md` — Contributor Covenant 2.1, `conduct@polybag.app`.
- `.github/ISSUE_TEMPLATE/bug_report.yml` — asks for area, carrier, deployment mode,
  sandbox vs production, browser, and commit SHA, with a redaction checkbox and a
  "this is not a vulnerability" gate. `feature_request.yml` and a `config.yml` that
  disables blank issues and routes security reports to the advisory form.
- `.github/PULL_REQUEST_TEMPLATE.md`.
- Created the `needs-triage` and `needs-info` GitHub labels — the templates apply
  `needs-triage`, and GitHub silently drops labels that do not exist. Matches the
  vocabulary in `docs/agents/triage-labels.md`.

**`.githooks/pre-push` — audits are now advisory**

Took the option the brief recommended. PHPStan still blocks; `composer audit` and
`npm audit` print a warning and let the push through, with a comment in the hook saying
why and `git push --no-verify` documented as the full escape hatch. The `security-audit`
workflow runs both on every push and pull request, so the gate is still there — in CI,
where a maintainer sees it, rather than on an outside contributor's machine.

Verified all three branches with stubbed `phpstan`/`composer`/`npm`: phpstan failure
exits 1, either audit failing exits 0 with a warning. Also ran the hook for real against
the current tree — clean, exit 0.

**Scope note: `README.md` said the project was MIT.**

The last line of `README.md` was `## License` / `[MIT](LICENSE)`, which is simply wrong —
`LICENSE` has been BSL 1.1 throughout. Leaving it while adding three files that say BSL
would have been the exact "implies an OSI licence" failure this issue's last acceptance
criterion guards against, so it was corrected here: a short accurate Licence section plus
a Contributing section linking the three new files. **Issue 06 still owns the rest** —
the full Additional Use Grant wording, the repo-wide "open source" language audit, and
the trademark note.

**Follow-ups**

- Issue 06 owns the rest of the licence language: the full Additional Use Grant wording
  in `README.md`, the repo-wide "open source" audit, and the trademark note.
- No CLA text is drafted yet — `CONTRIBUTING.md` says a maintainer sends it on first
  contribution, so one needs to exist before a first outside PR lands.
