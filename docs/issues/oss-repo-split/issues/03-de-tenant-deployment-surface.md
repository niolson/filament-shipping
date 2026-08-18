# De-tenant the public deployment surface

Status: ready-for-human
Category: enhancement
Type: human

## Parent

`docs/issues/oss-repo-split/PRD.md`

## What to build

The public repo's deployment files assume our hosted server. Someone cloning the repo
should be able to run PolyBag without any resource we operate, and without reading
around hosted-mode plumbing that does not apply to them.

### `docker-compose.yml`

Four services — `app`, `queue`, `import-queue`, `scheduler` — carry
`env_file: /opt/shared/shared-secrets.env`. This works for outsiders only because
`required: false` makes the missing file non-fatal, which is silent rather than
explanatory. Decide between:

- **Keep it, document it** — one comment block naming it as a hosted-mode injection
  point that self-hosters can ignore. Cheapest, and consistent with the existing note
  in `CLAUDE.md` that this file is intentionally *not* `.env`.
- **Move it to an override** — mirror the existing `docker-compose.onprem.yml`
  pattern with a `docker-compose.shared.yml` that lives in `polybag-ops`, leaving the
  public base file free of `/opt/shared` entirely.

The second is cleaner but splits the compose story across two repos; the first keeps
one file that works everywhere. Pick one and record the reasoning in the issue.

### `infra/`

`infra/shared/*.cnf` **must stay public** — [`docker-compose.yml:136-138`](../../../../docker-compose.yml)
mounts all three into the standalone MySQL service, and the comment there is explicit
that they are required together (mounting `mysql.cnf` alone leaves 8.4 with encryption
on and no keyring to encrypt with). Only `infra/shared/docker-compose.yml` is ours.

Open question for this issue: **`infra/gotenberg/`**. Gotenberg backs `GOTENBERG_URL`
for PDF rendering, so a self-hoster needs it running. Either keep it public, or fold
it into `docker-compose.onprem.yml` so the standalone profile brings it up directly.
Do not simply move it to `polybag-ops` without replacing it — that leaves self-hosters
with a config key and no way to satisfy it.

Once `infra/` is down to a handful of public files, reconsider whether `infra/README.md`
should be rewritten for the public repo (it currently describes a shared server) or
replaced with a short note.

### `scripts/install-onprem.sh` and `.env.example`

- Strip `*.polybag.app` defaults from the installer prompts and generated config.
- `.env.example:90` documents production mail as `noreply@updates.polybag.app`. Replace
  with a neutral example and let issue 07 own the explanation.

## Acceptance criteria

- [ ] A decision is recorded for the `/opt/shared/shared-secrets.env` question, and implemented
- [ ] A decision is recorded for `infra/gotenberg/`, and self-hosters have a working path to satisfy `GOTENBERG_URL`
- [ ] `infra/shared/*.cnf` remains public and the standalone MySQL service still starts with encryption and a working keyring
- [ ] `scripts/install-onprem.sh` completes an install with no `polybag.app` value anywhere in its output or generated `.env`
- [ ] `.env.example` contains no `polybag.app` hostname
- [ ] A clean-clone smoke test passes: `docker compose --profile standalone -f docker-compose.yml -f docker-compose.onprem.yml up -d` on a machine with no `/opt/shared`, reaching a working login page

## Blocked by

None - can start immediately

## Notes

The standalone smoke test is the real acceptance signal here. Everything else in this
issue is bookkeeping in service of it.
