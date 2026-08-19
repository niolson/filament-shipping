# De-tenant the public deployment surface

Status: done
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
explanatory.

**Decision (settled 2026-08-18): keep it, document it.** Add one comment block naming
`/opt/shared/shared-secrets.env` as a hosted-mode injection point that self-hosters can
ignore, consistent with the existing note in `CLAUDE.md` that this file is intentionally
*not* `.env`. Nothing else about the file changes.

The alternative considered was moving the four blocks into a `docker-compose.shared.yml`
override living in `polybag-ops`, leaving the public base file free of `/opt/shared`
entirely. Rejected on cost:

- Tenant directories are `git clone`s of the app repo
  ([`provision-tenant.sh:128`](../../../../scripts/provision-tenant.sh)), so a file kept
  in `polybag-ops` is not in the tenant's project directory and compose cannot resolve it
  by relative path.
- Every hosted invocation is currently a bare `docker compose` —
  [`deploy-tenant.sh:98`](../../../../scripts/deploy-tenant.sh),
  [`rotate-internal-secrets.sh:403`](../../../../scripts/rotate-internal-secrets.sh),
  [`deprovision-tenant.sh:105`](../../../../scripts/deprovision-tenant.sh), plus every
  `docker compose exec`/`logs` typed by hand on the server. All would need
  `-f docker-compose.yml -f /opt/shared/docker-compose.shared.yml`, and any invocation
  that forgets it silently acts on a differently-configured project.
- The one script-free workaround — setting `COMPOSE_FILE` in each tenant's `.env` —
  overloads the app's config file with deploy plumbing and breaks invisibly whenever
  `.env` is regenerated.

Four lines of `required: false` plus a comment is a smaller comprehension cost to a
self-hoster than a cross-repo compose split is to daily operations.

### `infra/`

`infra/shared/*.cnf` **must stay public** — [`docker-compose.yml:136-138`](../../../../docker-compose.yml)
mounts all three into the standalone MySQL service, and the comment there is explicit
that they are required together (mounting `mysql.cnf` alone leaves 8.4 with encryption
on and no keyring to encrypt with). Only `infra/shared/docker-compose.yml` is ours.

**`infra/gotenberg/` — settled 2026-08-18: it moves, and needs no replacement, because
the self-hoster's Gotenberg already exists.**

The earlier framing of this as an open question was wrong on the facts. There are two
separate Gotenberg deployments in the repo today:

- [`docker-compose.onprem.yml:11-13`](../../../../docker-compose.onprem.yml) already
  defines a `gotenberg` service, and
  [`install-onprem.sh:106`](../../../../scripts/install-onprem.sh) already writes
  `GOTENBERG_URL=http://gotenberg:3000`. A standalone self-hoster gets working PDF
  rendering today with no `infra/` directory at all. **This stays public.**
- `infra/gotenberg/docker-compose.yml` is the shared-server singleton — `container_name:
  gotenberg` on the external `shared` network, deployed to `/opt/gotenberg`, requiring the
  `reconnect-shared-networks.sh` dance after every recreate. Nothing in it is usable by a
  self-hoster. **This moves to `polybag-ops`.**

That leaves the same image pinned in both repos, which is not a Gotenberg quirk — it is
exactly what `mysql:8.4` and `redis:alpine` already do (see the cross-repo pin constraint
in the PRD). This issue owns the public half of that fix: whatever survives as the public
pin policy must name the sibling location for each of the three duplicated images.

Known gap, out of scope here: someone who clones the public repo and runs plain
`docker compose up -d` against their own MySQL (shared/external mode) gets no Gotenberg
service. If the public self-host target is standalone-only, say so in `.env.example`
next to `GOTENBERG_URL`; issue 07 can own the wider explanation.

Once `infra/` is down to a handful of public files, reconsider whether `infra/README.md`
should be rewritten for the public repo (it currently describes a shared server) or
replaced with a short note. **This is the one piece of the issue that cannot be finished
here**: the file opens "# Shared server infrastructure" and tables four directories
deploying to `/opt/shared`, `/opt/caddy`, `/opt/gotenberg`, and `/opt/uptime-kuma`, three
of which issue 04 deletes. Its shape is decidable now — a short note on why three `.cnf`
files live in `infra/shared/` and where the digest siblings are — but it cannot be written
until 04 settles what remains.

### `scripts/install-onprem.sh` and `.env.example`

- Strip `*.polybag.app` defaults from the installer prompts and generated config.
- `.env.example:90` documents production mail as `noreply@updates.polybag.app`. Replace
  with a neutral example and let issue 07 own the explanation.

## Acceptance criteria

- [x] The `/opt/shared/shared-secrets.env` comment block is in place on all four services, and no hosted invocation had to change
- [x] `docker-compose.onprem.yml` still brings up Gotenberg, and a standalone install satisfies `GOTENBERG_URL` with no `infra/gotenberg/` present (`infra/shared/*.cnf` is still required — an earlier wording said "no `infra/`", which would have left MySQL unencrypted)
- [x] The public pin policy names the sibling location for `mysql:8.4`, `redis:alpine`, and `gotenberg/gotenberg:8` (`infra/README.md`, "Images pinned in more than one place")
- [x] `.env.example` states which deployment modes ship a Gotenberg service
- [x] `infra/shared/*.cnf` remains public and the standalone MySQL service still starts with encryption and a working keyring — the installer's `Encryption at rest active` check passed on a clean clone
- [x] `scripts/install-onprem.sh` completes an install with no `polybag.app` value anywhere in its output or generated `.env` — it never contained one (`git log -S polybag.app -- scripts/install-onprem.sh` is empty); this criterion was written without checking
- [x] `.env.example` contains no `polybag.app` hostname — removed in `2394bdf` by issue 07, before this issue was started
- [x] A clean-clone smoke test passes: `scripts/install-onprem.sh` on a machine with no `/opt/shared`, reaching a working login page
- [x] That run reports `Encryption at rest active` — the installer asserts it directly, and every way it breaks leaves a healthy-looking server behind

## Blocked by

None - can start immediately

## Notes

**Run `scripts/install-onprem.sh`, not the bare compose command.** An earlier draft of the
acceptance criteria named
`docker compose --profile standalone -f docker-compose.yml -f docker-compose.onprem.yml up -d`
directly. That cannot succeed on a fresh checkout: `proxy` and `shared` are
`external: true` ([`docker-compose.yml:239-247`](../../../../docker-compose.yml)) and must
already exist, there is no `.env` for the bind mount or redis's `env_file`, and there is no
`APP_KEY`. The installer creates the networks, generates `.env` and the key, fixes the
`.cnf` permissions, runs exactly that compose command, and then verifies encryption at
rest — so it is both the only way the command works and the stronger signal.

The standalone smoke test is the real acceptance signal here. Everything else in this
issue is bookkeeping in service of it.

Both open questions in the original draft are now decided, and every acceptance criterion
except the `infra/README.md` rewrite is met. Two of them turned out to be already satisfied
when checked: `install-onprem.sh` never carried a `polybag.app` value, and `.env.example`
lost its one reference to `updates.polybag.app` in `2394bdf` under issue 07. Both had been
asserted rather than verified when this issue was drafted.
