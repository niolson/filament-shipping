# Stand up the private `polybag-ops` repo with filtered history

Status: ready-for-human
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

- [ ] `polybag-ops` exists, is private, and contains the Tier 1 paths with their commit history intact (`git log --follow docs/server-setup.md` shows more than one commit)
- [ ] `infra/shared/*.cnf` is **not** in the new repo — those stay public (see PRD)
- [ ] The public repo is untouched by this issue; no deletions yet (that is issue 04)
- [ ] A `README.md` at the root of `polybag-ops` states what it is, that it is the operational counterpart to the public repo, and which server it describes
- [ ] `security-reports/` is either carried over from the local working copy or deliberately left out — decide and record which; it has never been tracked in git, so `filter-repo` will not bring it
- [ ] The scratch clone used for filtering is deleted afterwards

## Blocked by

None - can start immediately

## Comments

> *Generated during planning; see the parent PRD for the full scan that justifies no history rewrite.*
