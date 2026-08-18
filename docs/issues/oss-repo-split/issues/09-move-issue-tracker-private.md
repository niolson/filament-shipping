# Move `docs/issues/` to the private repo

Status: ready-for-human
Category: chore
Type: human

## Parent

`docs/issues/oss-repo-split/PRD.md`

## What to build

**Sequenced last, deliberately.** This plan lives in `docs/issues/`, so moving the
tracker moves the plan. Doing it last keeps the remaining slices visible in the working
repo for the duration of the work; afterwards, tracking continues in `polybag-ops`.

Move everything under `docs/issues/` (36 files at time of writing, this plan included) — `data-source-improvements/`,
`special-services/`, `sso-mfa-federation/`, `tech-debt/`, and this `oss-repo-split/`
directory — into `polybag-ops`, preserving history the same way issue 01 did.

Then repair the tracker convention, which is wired into the repo's agent skills:

- `docs/agents/issue-tracker.md` states that issues live in `docs/issues/` in *this*
  repo. After the move that is wrong in the public repo and needs to either point at
  the private repo or be moved there itself.
- `docs/agents/triage-labels.md` is only meaningful alongside the tracker.
- `docs/agents/domain.md` describes the `CONTEXT.md` + `docs/adr/` layout, both of
  which **stay public** — so this one stays.
- `CLAUDE.md`'s Agent skills section lists all three (coordinate with issue 08).

Decide whether the public repo keeps a tracker at all. GitHub Issues is the natural
public front door once `SECURITY.md` and the templates from issue 05 are in place, and
outside contributors cannot open a markdown-file issue by pull request in any
reasonable way. Recommend: GitHub Issues for the public repo, markdown tracker for
`polybag-ops`.

Note that some planning content is genuinely product roadmap rather than deployment
detail — `special-services/` is carrier API research, `data-source-improvements/` is
feature work. Keeping it private is the settled call, but it is worth a look on the way
past for anything that would serve better as public documentation, particularly the
carrier API reference files.

## Acceptance criteria

- [ ] Every file under `docs/issues/` exists in `polybag-ops` with history preserved
- [ ] `docs/issues/` is gone from the public repo
- [ ] `docs/agents/issue-tracker.md` and `triage-labels.md` are moved or corrected — no public file claims a tracker that is not there
- [ ] `docs/agents/domain.md` remains public and accurate (`CONTEXT.md`, `docs/adr/` unchanged)
- [ ] A decision is recorded on the public issue front door, and if GitHub Issues, it is enabled with the issue 05 templates
- [ ] `CLAUDE.md`'s Agent skills section is correct
- [ ] Remaining open slices from this PRD are tracked in `polybag-ops` and none are lost in the move

## Blocked by

- `issues/01-stand-up-ops-repo.md`
- Every other slice in this PRD — this one closes the work out.
