# Rewrite `CLAUDE.md` and `AGENTS.md` down to app-only scope

Status: ready-for-agent
Category: documentation
Type: AFK

## Parent

`docs/issues/oss-repo-split/PRD.md`

## What to build

`CLAUDE.md` (28KB) currently documents the hosted deployment in detail: the three
deployment modes table, the shared-network Docker architecture diagram, tenant
isolation in shared mode, `provision-tenant.sh` / `deprovision-tenant.sh` /
`install-onprem.sh`, the `/opt/shared/shared-secrets.env` injection rationale, and a
demo-reset wrapper that lives in a private repo. After issue 04 most of that describes
files the public repo no longer contains.

This is a rewrite, not a trim — the deployment sections are load-bearing for how the
file explains the project, and cutting them leaves gaps rather than a shorter document.

- Public `CLAUDE.md` keeps: project overview, domain model, key workflows, hardware
  integration, API integrations, data import/export, commands, architecture, testing,
  and the standalone/on-prem deployment path only.
- The hosted-mode content moves to a `CLAUDE.md` in `polybag-ops`, where it will
  actually sit next to the scripts it describes.
- `AGENTS.md` (17KB) covers the same ground in a different voice and has drifted out of
  sync with `CLAUDE.md`. Decide whether it stays a separate document or becomes a
  pointer; either way it must not describe moved paths.
- The **Agent skills** section at the end of `CLAUDE.md` references
  `docs/agents/issue-tracker.md`, `docs/agents/triage-labels.md`, and
  `docs/agents/domain.md`. Issue 09 moves `docs/issues/` out from under the first two —
  coordinate so this section is correct after both land.

## Acceptance criteria

- [ ] No path referenced in public `CLAUDE.md` or `AGENTS.md` is absent from the public repo
- [ ] The standalone / on-prem deployment path is still fully documented publicly
- [ ] Hosted-mode content exists in `polybag-ops`, not merely deleted
- [ ] `AGENTS.md` and `CLAUDE.md` no longer contradict each other on structure, commands, or test invocation
- [ ] The Agent skills section reflects reality after issue 09

## Blocked by

- `issues/04-remove-ops-paths.md` — rewrite once, after the deletions settle
