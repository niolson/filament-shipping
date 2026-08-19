# Shared server infrastructure

Compose files for the services that run *alongside* tenants on a shared server,
rather than as part of any one tenant: the shared datastores, the TLS front
door, PDF rendering, and monitoring.

| Directory | Deploys to | Services |
| --- | --- | --- |
| `shared/` | `/opt/shared/` | MySQL, Redis |
| `caddy/` | `/opt/caddy/` | Caddy |
| `gotenberg/` | `/opt/gotenberg/` | Gotenberg |
| `uptime-kuma/` | `/opt/uptime-kuma/` | Uptime Kuma |

Tenant application containers are not here — those come from the root
`docker-compose.yml` via `provision-tenant.sh`.

## Why these live in the repo

They didn't, until a vulnerability scan of the running containers found 1874
fixable critical/high CVEs across images nothing was watching. Dependabot only
sees what is committed, so anything defined solely in `/opt/*` on the server
was invisible to it — and images referenced by mutable tags (`caddy:alpine`,
`gotenberg/gotenberg:8`) silently adopted whatever was newest on any
`docker compose up -d`, with no review and no cooldown.

Committing them puts these images under the same update policy as the rest of
the project's dependencies.

## Deploying a change

These files are not deployed by `deploy-tenant.sh` — that only handles tenants.
Copy them into place and recreate:

```bash
# From a checkout on the server, e.g. /opt/tenants/test
cp infra/shared/* /opt/shared/          # excludes .env, which is server-only
cd /opt/shared && docker compose up -d
```

Two services need care beyond `up -d`:

- **Gotenberg and the shared datastores** must be reachable from every
  per-tenant `shared-<tenant>` network. Compose only attaches them to the
  networks named in their own file, so recreating one drops those attachments
  and cuts tenants off. Run `scripts/reconnect-shared-networks.sh` afterwards —
  it is idempotent, so running it when it wasn't needed costs nothing.
- **Caddy** terminates TLS for every tenant. Recreating it drops all inbound
  HTTPS for a few seconds. Do it in a quiet window.

Secrets are never committed. `/opt/shared/.env` (datastore passwords) and
`/opt/shared/shared-secrets.env` (injected into tenant containers) stay on the
server; see `.env.example` and `shared-secrets.env.example`.

## Update policy

Every image is pinned **by digest**, not by tag. A tag is a moving pointer —
pulling `caddy:alpine` gives you whatever its publisher pushed most recently,
which could be minutes old. A digest is immutable, so what runs in production
is exactly what was reviewed.

### Images pinned in more than one place

Three images run in two separate deployments: the shared-server singleton here,
and the self-host stack in the root compose files. Each copy is pinned
independently, and neither location mentions the other.

| Image | Shared server | Self-host |
| --- | --- | --- |
| `mysql:8.4` | `shared/docker-compose.yml` | `docker-compose.yml` (standalone profile) |
| `redis:alpine` | `shared/docker-compose.yml` | `docker-compose.yml` (standalone profile) |
| `gotenberg/gotenberg:8` | `gotenberg/docker-compose.yml` | `docker-compose.onprem.yml` |

Dependabot opens a PR per occurrence, so they do not drift unnoticed. A hand-bump
that skips the sibling does leave the other deployment on the old digest — which
is tolerable, since they are independent deployments, but should be a choice
rather than an oversight.

### How pins move

Pins move through Dependabot PRs, under the `docker-compose` ecosystem in
`.github/dependabot.yml`, with a **7-day cooldown**. The delay is deliberate:
a compromised or broken upstream release is usually caught within days, and
nothing here is urgent enough to justify adopting an image the hour it ships.

The counterweight is the monthly Trivy scan (`scripts/security-scan-host.sh
--images`, see `docs/server-setup.md`), which reports what each pin is costing
in unpatched CVEs. Pinning without scanning is how images end up years stale;
pinning *with* scanning is a deliberate, measured delay.

### When to break the cooldown

Skip the wait for a critical that is being actively exploited in the wild.
Trading a hypothetical supply-chain risk for a confirmed exploited one is the
wrong side of that trade. Bump the digest by hand, note why in the commit, and
let Dependabot catch up.

### Where the cooldown does not apply

OS packages inside the app image are refreshed on every build via
`APT_CACHE_BUST` in the `Dockerfile`, with no delay. That is intentional and
not an inconsistency: distribution security repositories are curated and signed
by the distro's own security team, which is itself the review gate a cooldown
would otherwise provide. Delaying there adds exposure without adding scrutiny.
The cooldown is aimed at upstream registries and release binaries, where the
publisher is often a single maintainer and no such gate exists.
