# Rewrite `CLAUDE.md` and `AGENTS.md` down to app-only scope

Status: done
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

- [x] No path referenced in public `CLAUDE.md` or `AGENTS.md` is absent from the public repo — checked by extracting every backticked path-like token from both files and testing it. Turned up two real drifts, fixed here: `e2e/` has no tracked files at all (so the `npm run test:e2e` scripts invoke an uncommitted harness, and browser coverage is actually Pest 4 in `tests/Browser/`), and `ImportSourceInterface`/`ImportSourceFactory` had been renamed to `DataSourceInterface`/`DataSourceFactory`
- [x] The standalone / on-prem deployment path is still fully documented publicly — expanded, in fact: the service table now lists `import-queue`, `scheduler`, and `gotenberg`, which the old three-mode table omitted
- [x] Hosted-mode content exists in `polybag-ops`, not merely deleted — `polybag-ops` PR #2 adds a `CLAUDE.md` there
- [x] `AGENTS.md` and `CLAUDE.md` no longer contradict each other on structure, commands, or test invocation — resolved by construction rather than by syncing: `AGENTS.md` names `CLAUDE.md` as canonical and no longer restates any of it. Its unique content (repository layout, commit/PR guidance, security notes) moved into `CLAUDE.md`
- [x] The Agent skills section reflects reality **as of now**, and issue 09 owns the post-move state — its acceptance criteria were amended to name both files, since each carries its own copy of the section

## Blocked by

- `issues/04-remove-ops-paths.md` — rewrite once, after the deletions settle
