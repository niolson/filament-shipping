# Stand up the private `polybag-ops` repo with filtered history

Status: done
Category: infrastructure
Type: human

## Parent

`docs/issues/oss-repo-split/PRD.md`

## What to build

A new **private** repo, `polybag-ops`, seeded from this repo's history so that blame
and commit context survive on the moved files. History preservation matters most for
`docs/server-setup.md` — the MySQL TDE keyring migration section encodes hard-won
detail (which image ID, why the block gates instead of calling `exit`, why the failed
verifier container is deliberately not `--rm`) whose provenance is worth keeping.

Approach: clone to a scratch directory, run `git filter-repo` keeping only the Tier 1
paths from the PRD, then push to the new remote. Do **not** run `filter-repo` against
the working clone.

Paths to keep (full list in the PRD table):

- `docs/server-setup.md`, `docs/cloudflare-hardening/`, `docs/tenant-network-isolation.md`, `docs/customer-ssh-tunnel-setup.md`
- `scripts/backup-db.sh`, `backup-nightly.sh`, `restore-db.sh`, `rotate-backup-key.sh`, `rotate-storage-key.sh`, `rotate-internal-secrets.sh`, `lib/backup-keys.sh`
- `scripts/deploy-tenant.sh`, `deprovision-tenant.sh`, `reconnect-shared-networks.sh`, `provision-tenant.sh`
- `scripts/security-scan-host.sh`, `security-scan-origin.sh`
- `infra/caddy/`, `infra/uptime-kuma/`, `infra/shared/docker-compose.yml`, `infra/shared-secrets.env.example`, `infra/README.md`, `infra/.env.example`, `infra/backup.env.example`

`docs/issues/` is **not** in this pass — it moves in issue 09, after the rest of the
work is done, so the tracker stays usable meanwhile.

Note that `scripts/backup-local-db.sh` and `restore-local-db.sh` operate on a
developer's local database from `.env`, not on the server. They are developer tooling
— leave them public.

## Acceptance criteria

- [x] `polybag-ops` exists, is private, and contains the Tier 1 paths with their commit history intact (`git log --follow docs/server-setup.md` shows more than one commit)
- [x] `infra/shared/*.cnf` is **not** in the new repo — those stay public (see PRD)
- [x] The public repo is untouched by this issue; no deletions yet (that is issue 04)
- [x] A `README.md` at the root of `polybag-ops` states what it is, that it is the operational counterpart to the public repo, and which server it describes
- [x] `security-reports/` is either carried over from the local working copy or deliberately left out — decide and record which; it has never been tracked in git, so `filter-repo` will not bring it
- [x] The scratch clone used for filtering is deleted afterwards

## Blocked by

None - can start immediately

## Comments

> *Generated during planning; see the parent PRD for the full scan that justifies no history rewrite.*

---

## Implementation notes (2026-08-18)

`niolson/polybag-ops` exists and is private. Seeded from `origin/main` at `65e3622`
via `git filter-repo` in a scratch clone; the working clone was never touched.

**Result:** 82 filtered commits + 1 new (README/gitignore) = 83; 43 carried files.
`git log --follow docs/server-setup.md` shows **35** commits, back to
`77d76a0 Add server setup docs, tenant provisioning script, and fix docker-compose for production`
— so the MySQL TDE keyring provenance survived, which was the point.
Executable bits carried over intact (`scripts/lib/backup-keys.sh` stays `100644`
in both repos, matching the public repo; it is sourced, not executed).

**`infra/shared/*.cnf` verified absent** from every revision of the new repo, not
just `HEAD` — `--path infra/shared/docker-compose.yml` was used rather than
`--path infra/shared/`, so `mysql.cnf`, `mysqld.my`, and
`component_keyring_file.cnf` were never in the filtered history at all.

**The public repo is untouched.** No files removed, no commits added here beyond
this tracker update. Deletion is still issue 04, and still blocked by issue 02.

### Decision: `security-reports/` was *not* carried over

Left out deliberately. The local working copy holds 16 files / 66 MB, almost all
of it Trivy and ZAP JSON from five host scans and one origin scan. It is
point-in-time, regenerable by re-running the scan, and a stale scan is worth less
than a fresh one — putting 66 MB of superseded scanner output into a fresh repo's
first commit buys nothing. The files remain on the machine that produced them.
`polybag-ops/.gitignore` keeps `/security-reports/` ignored, matching the public
repo, so the scan scripts still write there without dirtying the tree.

### Carried forward

- **`infra/gotenberg/` is not in the new repo** — issue 03 owns that call. But
  `infra/README.md` moved *with* its table row for `gotenberg/ → /opt/gotenberg/`,
  so the ops repo currently documents a directory it does not contain. Whichever
  way issue 03 decides, that table needs a matching edit in `polybag-ops`.
- **`infra/README.md` points at `.github/dependabot.yml`**, which did not move —
  the new repo has no `.github/` at all yet. That is exactly the regression the
  PRD flags, and it is issue 02's job. Until then the ops repo's stated update
  policy is aspirational.
- The scratch clone and the verification clone were both deleted.
