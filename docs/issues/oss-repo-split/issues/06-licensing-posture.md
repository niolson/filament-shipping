# Make the licensing posture honest (keep BSL)

Status: done
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

- [x] `README.md` has a Licence section stating BSL 1.1, the use grant, the change date, and the commercial contact
- [x] No file in the public repo describes the project as "open source" or otherwise implies an OSI licence
- [x] A trademark/branding note exists and covers the post-change-date period
- [x] `LICENSE` itself is unmodified

## Blocked by

None - can start immediately

## Notes

**Resolved 2026-08-18: QZ Tray may be named freely in this repo.** The
"never name the print bridge vendor" rule applies to customer-facing marketing copy
(the polybag.app site, `llms.txt`, JSON-LD, sales material), where the local agent is
described generically because a white-labeled licence is planned. The app repo,
`README.md`, and `docs/` are explicitly outside that rule. No action needed — do not
genericise QZ Tray references while doing the licence-language audit above.

## Completion notes

**`README.md` — expanded `## License`**

Issue 05 had already replaced the wrong "MIT" line with a short accurate section. This
issue expanded it: licensor named, BSL 1.1 called out as not OSI-approved and not
recognised by GitHub, the Additional Use Grant split into what it permits and what it
does not, and a plain statement that shipping real customer parcels as a business is the
excluded case.

The change date is stated as **March 11, 2030 *or* four years after a given version was
first published, whichever comes first**. The bare "converts on March 11, 2030" framing
used in `CONTRIBUTING.md` and `SECURITY.md` is not quite what `LICENSE` says — BSL 1.1's
standard terms apply per version and include the four-year clause, which can land
earlier. The README now matches the licence text.

**`README.md` — new `### Trademark and branding`**

Says the licence covers the code and not the PolyBag name, logo, or `polybag.app`
branding, and that the Apache-2.0 conversion will not change that, since Apache-2.0
grants copyright and patent rights but no trademark rights. That is what makes the note
hold past the change date. Forks are fine under their own name; factual "based on
PolyBag" references are explicitly allowed.

`CONTRIBUTING.md` carries a four-line version of the same point in its licence section,
linking to the README anchor — that is where someone contemplating a fork is reading.

**Language audit — nothing first-party to fix**

Swept every tracked file for "open source", "opensource", "free to use", "MIT-style",
and "OSI-approved". Every remaining hit is legitimate:

- `README.md`, `CONTRIBUTING.md`, `SECURITY.md` — the "source-available, not open
  source" and "not an OSI-approved licence" disclaimers themselves.
- `config/database.php` ("Redis is an open source…") and `config/logging.php` ("handlers
  and formatters that you're free to use") — stock Laravel comments describing Redis and
  Monolog, not PolyBag.
- `public/js/filament/**`, `resources/views/welcome.blade.php`, `composer.lock` — MIT
  notices belonging to vendored third-party code (Chart.js, FilePond, Tailwind). Correct
  as-is; removing them would be a licence violation.

**`composer.json` — stale skeleton metadata**

Adjacent to the brief rather than named by it, but the same "describe itself accurately"
concern. `name` was still `laravel/laravel` and `description` still "The skeleton
application for the Laravel framework" — an upstream package that is MIT. `license` was
already correctly `BUSL-1.1`. Now `polybag/polybag` with a real description and
keywords.

`name` feeds Composer's lock content-hash, so `composer.lock` needed refreshing.
Ran `composer update --lock --no-scripts`: diffed the package lists before and after and
no package or version changed — the only diff in `composer.lock` is the hash line.
`composer validate` is clean.

**Not done**

- `docs/issues/oss-repo-split/PRD.md` still says the goal is a repo that "reads as a real
  open-source-style project". It describes the planning goal rather than the project's
  licence, the same paragraph already defers the licence question to this issue, and
  issue 09 moves the tracker out of the public repo. Left alone.
- `CONTRIBUTING.md` and `SECURITY.md` keep the simpler "converts to Apache-2.0 on
  March 11, 2030" wording. Accurate enough for their purpose, and the README is the
  place carrying the precise version.
