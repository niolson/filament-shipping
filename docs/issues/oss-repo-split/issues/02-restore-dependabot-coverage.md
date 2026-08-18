# Re-establish Dependabot / CVE coverage in `polybag-ops`

Status: done
Category: security
Type: human

## Parent

`docs/issues/oss-repo-split/PRD.md`

## What to build

`infra/README.md` records the reason those compose files are committed to a repo at
all: a container scan found **1874 fixable critical/high CVEs** across images nothing
was watching, because Dependabot only sees what is committed and anything defined
solely in `/opt/*` on the server was invisible to it. Images referenced by mutable
tags (`caddy:alpine`, `gotenberg/gotenberg:8`) had been silently adopting whatever was
newest on every `docker compose up -d`.

Moving `infra/` into a new repo undoes that control unless the new repo reproduces it.
This issue makes the private repo watch what the public one used to.

Work:

- Port `.github/dependabot.yml` to `polybag-ops`, with the `docker` ecosystem pointed
  at every directory that now holds a compose file (`infra/caddy/`,
  `infra/uptime-kuma/`, `infra/shared/`, and `infra/gotenberg/` if issue 03 moves it).
- Confirm the pinned-digest discipline survived the move — every image reference
  should still be `name:tag@sha256:...`, not a bare tag.
- Decide whether the scheduled rebuild-and-scan workflow
  (`.github/workflows/scheduled-rebuild.yml`) needs a counterpart in `polybag-ops` for
  the infra images, or whether Dependabot alone is sufficient there. The public
  workflow scans the *app* image and should stay public.

## Acceptance criteria

- [x] `polybag-ops` has a `dependabot.yml` covering every directory containing a compose file
- [x] Dependabot has actually run once in the new repo and its results reviewed — configuration alone does not close this
- [x] No image reference in `polybag-ops` uses a mutable tag without a digest
- [x] A decision is recorded on infra-image scanning (Dependabot only, or Dependabot plus a scheduled scan)
- [x] `infra/README.md` in the new repo still explains *why* these files are committed — that rationale must not be lost in the move

## Blocked by

`issues/01-stand-up-ops-repo.md`

## Blocks

`issues/04-remove-ops-paths.md` — do not delete `infra/` from the public repo until
the private repo is demonstrably watching those images.

---

## Implementation notes (2026-08-18)

Two commits in `polybag-ops`: `d8758e0` (dependabot config + scanning decision) and
`84ea009` (cloudflared pin). Nothing in the public repo changed except this file.

**Config.** `.github/dependabot.yml` in `polybag-ops`, `docker-compose` ecosystem over
`/infra/shared`, `/infra/caddy`, `/infra/uptime-kuma`, plus
`/docs/cloudflare-hardening/option-b/cloudflared` (see pinning below). Carried the
7-day cooldown and the MySQL `semver-major` ignore across verbatim, comment and all —
the calver/LTS reasoning is still exactly why we hold at 8.4.

`/infra/gotenberg` is deliberately **not** listed: it is still in the public repo and
still watched by the config there, pending issue 03. **There is no coverage gap right
now** — every compose file is watched by exactly one of the two repos. If issue 03
moves Gotenberg, add the directory here in the same commit; the config carries a
comment saying so.

Repo security settings enabled via the API: `dependabot_security_updates`,
Dependabot alerts (`/vulnerability-alerts` → 204), automated security fixes
(`enabled: true, paused: false`). Alerts currently: **0**.

**Decision recorded: Dependabot only, no CI scan in `polybag-ops`.** Written into that
repo's `infra/README.md` under "Why there is no CI scan in this repo". The short
version: the monthly host scan (`/etc/cron.d/trivy-host-scan`, `--images`) already
covers these images and discovers them from `docker ps`, so it sees what is genuinely
running — including Gotenberg and the tenant app containers, which are not defined in
that repo — plus host packages and the kernel. It also scans the digest actually
deployed, where a CI job would scan the digest a compose file *claims*; those diverge
precisely when someone deploys by hand. A CI scan would add action-SHA maintenance to
a repo that otherwise needs no `github-actions` ecosystem, for no new signal. The
public `scheduled-rebuild.yml` scans the app image and stays public.

**Pinning.** All five compose images are `tag@sha256:…`. I checked each pin against
upstream with `docker buildx imagetools inspect` — `caddy:alpine`, `mysql:8.4`,
`redis:alpine`, and `louislam/uptime-kuma:2` are all **already at the current upstream
digest**, so zero Dependabot PRs is the correct result here, not a sign of a broken
config.

Three refs were on mutable tags. Resolved per maintainer decision:

- `cloudflare/cloudflared:latest` in the option-B docs — **pinned**. We deploy option
  A, but that compose file is copy-pasteable into production, so `:latest` there was a
  live foot-gun. Its directory was added to `dependabot.yml` at the same time; an
  unwatched pin is only marginally better than a mutable tag.
- `zaproxy/zap-stable` and `curlimages/curl:latest` in `scripts/security-scan-origin.sh`
  — **left mutable on purpose**, with the reasoning written into the script. They are
  `--rm` containers alive for one scan, not services drifting on `docker compose up -d`.
  A scanner running last year's detection rules reports a clean bill of health it did
  not earn, and no Dependabot ecosystem reads shell scripts, so a pin there would be
  unwatched and would rot silently. Note this diverges from the public repo's copy of
  that script until issue 04 deletes it — harmless, since 04 is a deletion, not a merge.

### Dependabot run confirmed (2026-08-18, same day)

Five version-update jobs on <https://github.com/niolson/polybag-ops/network/updates>,
all green, all "No PRs affected", spread over the ~15 minutes after the two pushes.
No config error. Zero PRs is the **correct** result, not a silent failure: every pin
was independently checked against upstream with `docker buildx imagetools inspect`
before the config landed, and all of them were already at the current digest.

**Correction to what an earlier draft of this note claimed.** It cited public PR #119
as proof the compose ecosystem works. That is wrong — #119 is the `docker` ecosystem
(the `Dockerfile`). The real evidence is better: the public repo has **six**
`dependabot/docker_compose/*` PRs — note the *underscore*, which is why a hyphenated
search came back empty and produced the wrong conclusion. Three of them are against
`/infra/shared` specifically (#111, #109, #100), so the exact ecosystem, on the exact
directory, has demonstrably produced PRs under this config shape.

PR #100 is a bonus confirmation: `bump mysql from 8.4 to 26.7 in /infra/shared`,
closed. That is precisely the calver Innovation-release bump the `semver-major` ignore
rule exists to suppress, so carrying that rule across was right.

**One thing worth a second look.** The manifest list on that page shows only
`docs/.../cloudflared/docker-compose.yml` — the three `infra/` directories are not
listed as rows, even though the config names them and the files are on `main`. All
four compose files are structurally identical (plain `services:` with a
`tag@sha256:` image), so there is no parse-level reason for a split. Most likely a
display artifact of a list still filling in, given the jobs ran green and outnumber
the directories. If a Dependabot PR ever lands for `infra/` this is settled; until one
does, re-check the page before relying on it.

### Observed in passing, not acted on

The **public** repo pins its GitHub Actions by SHA but has no `github-actions`
Dependabot ecosystem, so those pins are unwatched and go stale — the same class of
problem this issue exists to fix, one repo over. Out of scope here; worth its own issue.
