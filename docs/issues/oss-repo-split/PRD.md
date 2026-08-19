# Split deployment-specific tooling out of the public repo

Status: needs-triage
Type: mixed (human-led infra moves, AFK-able doc work)

## Problem

`niolson/polybag` is public, but it carries three kinds of content that belong to
the `*.polybag.app` hosted deployment rather than to the product:

1. **Server operations** — the VPS runbook, Cloudflare hardening, backup/rotation
   scripts, host and origin security scans, shared-infrastructure compose files.
2. **Internal planning** — `docs/issues/` (PRDs, triage state, agent briefs).
3. **Hosted-service assumptions baked into the app surface** — the OAuth broker at
   `connect.polybag.app`, the Resend domain `updates.polybag.app`, and a root
   `docker-compose.yml` that reaches for `/opt/shared/shared-secrets.env`.

The goal is a public repo that reads as a real open-source-style project — clear
contribution and security paths, a self-host story that works without anything we
run — while the deployment specifics move to a private `polybag-ops` repo.

## Scope decisions (settled 2026-08-18)

- **The licence does not change.** [`LICENSE`](../../../LICENSE) stays Business Source
  License 1.1. We are making the project *OSS-shaped*, not OSS. Everything below is
  about contribution hygiene, security-reporting paths, and removing deployment
  coupling — not about granting new rights. Corollary: the repo must stop describing
  itself in ways that imply an OSI licence (see issue 06).
- **`docs/issues/` goes private.** It moves to `polybag-ops` along with the ops
  content (issue 09).
- **No git history rewrite.** See below.

## Why no history rewrite

A full scan of tracked files and all history found:

- `.env` was never committed; no credential-shaped values in any revision of
  `docs/`, `scripts/`, `infra/`, or `.env.example`.
- `security-reports/` is gitignored and has never been tracked (0 files).
- The one flagged filename, `public/qz-certificate.pem`, was a **public** certificate
  (`CN=shipping-native.test`), removed in `cb9541e` and gitignored since.
- No LAN or tailnet IPs, and no real customer hostnames — every `*.polybag.app`
  reference is our own infrastructure (`connect`, `updates`) or a placeholder
  (`acme`, `test`, `real`).

So a plain move-and-delete is enough. The tradeoff, accepted deliberately: the
content of `docs/server-setup.md` and `docs/cloudflare-hardening/` **stays readable
in public git history forever**. It describes architecture and hostnames, contains no
credentials, and has already been public. Rewriting history would mean a force push,
a broken cache on GitHub's side, and no control over existing forks — worse cost for
no secret actually protected.

**If that assessment ever stops holding** — someone commits a real secret before the
split lands — stop and reassess before deleting anything; deletion from `HEAD` is not
removal from history.

## What moves

### Tier 1 — unambiguously deployment-specific

| Path | Why |
| --- | --- |
| `docs/server-setup.md` (58KB) | VPS runbook: Hetzner, Caddy, MySQL TDE keyring migration, cron, Resend domain |
| `docs/cloudflare-hardening/` (24 files) | Our CF account, Authenticated Origin Pull, fail2ban jails named `polybag.local` |
| `docs/tenant-network-isolation.md` | Shared-server multi-tenant design |
| `docs/customer-ssh-tunnel-setup.md` | Our support process |
| `scripts/backup-db.sh`, `backup-nightly.sh`, `restore-db.sh`, `rotate-backup-key.sh`, `rotate-storage-key.sh`, `rotate-internal-secrets.sh`, `lib/backup-keys.sh` | Hetzner S3 endpoint as a hardcoded default; our keyring and rotation policy |
| `scripts/deploy-tenant.sh`, `deprovision-tenant.sh`, `reconnect-shared-networks.sh` | Assume the `/opt/tenants/` layout |
| `scripts/security-scan-host.sh`, `security-scan-origin.sh` | Scan *our* host and origin; mail from `noreply@updates.polybag.app` |
| `scripts/provision-tenant.sh` | Multi-tenant control plane, not a self-host tool |
| `infra/caddy/`, `infra/uptime-kuma/`, `infra/shared/docker-compose.yml`, `infra/shared-secrets.env.example` | Our server's stack |
| `docs/issues/` | Internal planning (issue 09) |

### Stays public

- `scripts/install-onprem.sh` — the self-host path, once de-polybag'd.
- `scripts/qz-provision/` and `docs/qz-tray-provisioning.md` — a product feature.
- `docs/adr/`, `CONTEXT.md`, `README.md`, `docs/agents/`.
- **`infra/shared/*.cnf`** — cannot move. [`docker-compose.yml:136-138`](../../../docker-compose.yml)
  mounts `mysql.cnf`, `mysqld.my`, and `component_keyring_file.cnf` into the
  **standalone** MySQL service, which is the self-host path. Only the compose file in
  that directory is ours.
- **`docker-compose.onprem.yml`'s `gotenberg` service** — this is the self-hoster's
  Gotenberg and it stays. `infra/gotenberg/docker-compose.yml` is a *different*
  deployment of the same image (a `container_name: gotenberg` singleton on the external
  `shared` network) and moves with the rest of `infra/`. Settled in issue 03.

## Constraint discovered: moving `infra/` regresses CVE watching

[`infra/README.md`](../../../infra/README.md) records *why* those compose files are
committed at all: a scan of the running containers found 1874 fixable critical/high
CVEs across images nothing was watching, because **Dependabot only sees what is
committed** and anything defined solely in `/opt/*` on the server was invisible to it.

Moving `infra/` into `polybag-ops` therefore silently undoes that control unless the
new repo has Dependabot enabled on the same paths, with the same pinned-digest
discipline. Issue 02 covers this, and it **blocks the deletion in issue 04** — do not
remove `infra/` from the public repo until the private repo is demonstrably watching
those images.

## Constraint discovered: the split turns in-repo pin duplication into cross-repo

Three images are pinned twice — once for what self-hosters run, once for the
shared-server singleton — and today both copies sit in this repo:

| Image | Public repo (self-host) | Moves to `polybag-ops` |
| --- | --- | --- |
| `mysql:8.4` | `docker-compose.yml:70`, `:117` | `infra/shared/docker-compose.yml:23`, `:63` |
| `redis:alpine` | `docker-compose.yml:146` | `infra/shared/docker-compose.yml:86` |
| `gotenberg/gotenberg:8` | `docker-compose.onprem.yml:12` | `infra/gotenberg/docker-compose.yml:19` |

All six digests are currently identical. After the split, each repo's Dependabot bumps
its own copy independently, so the pairs will drift apart in time. That is acceptable —
they are genuinely independent deployments — but nothing currently tells a reader that a
sibling copy exists at all. [`infra/README.md`](../../../infra/README.md) explains why
pins are digests and how to bump one, and says nothing about there being more than one.

Requirement: whichever document survives as the public pin policy must name the sibling
location for each image, and `polybag-ops` must do the same in reverse. Issue 03 owns the
public side; issue 01 owns the private side.

## Slices

1. `issues/01-stand-up-ops-repo.md` — create `polybag-ops` with filtered history
2. `issues/02-restore-dependabot-coverage.md` — re-establish CVE watching in the new repo — **blocks 04**
3. `issues/03-de-tenant-deployment-surface.md` — decide what the public compose/install path looks like
4. `issues/04-remove-ops-paths.md` — the deletion commit in the public repo
5. `issues/05-contribution-and-security-surface.md` — `SECURITY.md`, `CONTRIBUTING.md`, CoC, templates, private vuln reporting
6. `issues/06-licensing-posture.md` — keep BSL, make the language honest, add a trademark note
7. `issues/07-self-hoster-decoupling-docs.md` — broker, BYO carrier credentials, mail defaults
8. `issues/08-rewrite-agent-guidance.md` — `CLAUDE.md` / `AGENTS.md` down to app-only scope
9. `issues/09-move-issue-tracker-private.md` — `docs/issues/` to `polybag-ops` — **sequenced last**

Independent starters: 01, 03, 05, 06, 07.

## Note on the recursion

This plan lives in `docs/issues/`, which issue 09 moves out of the public repo. That
is why 09 is sequenced last — the plan stays visible in the working repo for the
duration of the work, then travels to `polybag-ops` with the rest of the tracker and
the remaining slices are tracked there.
