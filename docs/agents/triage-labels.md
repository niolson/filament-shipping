# Triage Labels

The skills speak in terms of five canonical triage roles. This file maps those roles to
the actual label strings used in this repo's issue tracker.

| Label in mattpocock/skills | Label in our tracker | Meaning                                  |
| -------------------------- | -------------------- | ---------------------------------------- |
| `needs-triage`             | `needs-triage`       | Maintainer needs to evaluate this issue  |
| `needs-info`               | `needs-info`         | Waiting on reporter for more information |
| `ready-for-agent`          | `ready-for-agent`    | Fully specified, ready for an AFK agent  |
| `ready-for-human`          | `ready-for-human`    | Requires human implementation            |
| `wontfix`                  | `wontfix`            | Will not be actioned                     |
| —                          | `done`               | Implemented and verified; kept for history |
| —                          | `reference`          | Not a work item; background findings kept alongside issues |

When a skill mentions a role (e.g. "apply the AFK-ready triage label"), use the
corresponding label string from this table.

These are `Status:` lines in the Markdown files under `docs/issues/`. The same repo's
**GitHub Issues** carry `needs-triage`, `needs-info`, and `wontfix` as real GitHub labels
— those are the three that mean something to an outside reporter waiting on an answer.
`ready-for-agent` and `ready-for-human` are deliberately **not** defined on GitHub: they
describe how we schedule work, and a reporter has no use for the distinction. So they
exist only as `Status:` lines here.
