# Issue tracker: Local Markdown

Issues and PRDs for this repo live as markdown files in `docs/issues/`, committed to
the repo so history, comments, and triage state survive machine loss and are visible
to agents working in a fresh clone or worktree.

`.scratch/` is gitignored and is **not** the issue tracker. It holds throwaway
working artifacts: scan output, security evidence, vendor spec dumps, binaries.

## Conventions

- One feature per directory: `docs/issues/<feature-slug>/`
- The PRD is `docs/issues/<feature-slug>/PRD.md`
- Implementation issues are `docs/issues/<feature-slug>/issues/<NN>-<slug>.md`, numbered from `01`
- Triage state is recorded as a `Status:` line near the top of each issue file (see `triage-labels.md` for the role strings)
- Comments and conversation history append to the bottom of the file under a `## Comments` heading

## When a skill says "publish to the issue tracker"

Create a new file under `docs/issues/<feature-slug>/` (creating the directory if needed).

## When a skill says "fetch the relevant ticket"

Read the file at the referenced path. The user will normally pass the path or the issue number directly.

## Relationship to ROADMAP.md

`ROADMAP.md` holds durable strategic intent — what we plan to build, in what order,
and why, including explicitly deferred work and architectural guardrails. Issues hold
the how: specific work sliced into independently grabbable tickets, with test notes
and implementation history.

Don't duplicate between them. A roadmap entry may reference an issue directory once
work on it starts.

## What stays in `.scratch/`

Security work is the one exception to "issues go in `docs/issues/`". The
`.scratch/owasp-pentest/` and `.scratch/sp-api-security/` directories stay
gitignored — their issue files describe vulnerabilities with file-level detail, and
committing that to permanent git history is a deliberate decision, not a default.
Move them only if that tradeoff has been considered.
