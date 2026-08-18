# Make the licensing posture honest (keep BSL)

Status: ready-for-agent
Category: documentation
Type: AFK

## Parent

`docs/issues/oss-repo-split/PRD.md`

## What to build

**Decision: the licence does not change.** `LICENSE` stays Business Source License 1.1
— Licensor "POLYBAG.APP LLC", non-commercial use only, change date March 11 2030,
converting to Apache-2.0. This issue is about making the repo describe itself
accurately, not about granting rights.

BSL 1.1 is *source-available*, not open source: it is not OSI-approved, GitHub will
not surface it as a recognised licence, and "open source" means something specific to
contributors that BSL does not deliver. Making the repo look like an OSS project while
the licence says otherwise is the failure mode to avoid — it invites contributions
under a misunderstanding.

Work:

- Add a short **Licence** section to `README.md`: BSL 1.1, what the Additional Use
  Grant does and does not permit (personal, educational, internal evaluation and
  development use are fine; running it in production for a commercial entity is not),
  the change date, and `license@polybag.app` for commercial terms.
- Audit the repo for language that implies OSI licensing — "open source", "free to
  use", "MIT-style" — and correct it. Prefer "source-available".
- Add a **trademark and branding note**. The code being readable does not make the
  PolyBag name reusable, and a fork shipping under the same name is the specific thing
  to prevent. Worth stating even while the licence restricts commercial use, because
  the 2030 change date converts to Apache-2.0 and Apache-2.0 grants no trademark rights
  either — the note should still hold then.
- Keep the change-date conversion visible in the README so the eventual Apache-2.0
  transition is not a surprise to anyone building on it.

## Acceptance criteria

- [ ] `README.md` has a Licence section stating BSL 1.1, the use grant, the change date, and the commercial contact
- [ ] No file in the public repo describes the project as "open source" or otherwise implies an OSI licence
- [ ] A trademark/branding note exists and covers the post-change-date period
- [ ] `LICENSE` itself is unmodified

## Blocked by

None - can start immediately

## Notes

**Resolved 2026-08-18: QZ Tray may be named freely in this repo.** The
"never name the print bridge vendor" rule applies to customer-facing marketing copy
(the polybag.app site, `llms.txt`, JSON-LD, sales material), where the local agent is
described generically because a white-labeled licence is planned. The app repo,
`README.md`, and `docs/` are explicitly outside that rule. No action needed — do not
genericise QZ Tray references while doing the licence-language audit above.
