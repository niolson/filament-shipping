# Re-establish Dependabot / CVE coverage in `polybag-ops`

Status: ready-for-human
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

- [ ] `polybag-ops` has a `dependabot.yml` covering every directory containing a compose file
- [ ] Dependabot has actually run once in the new repo and its results reviewed — configuration alone does not close this
- [ ] No image reference in `polybag-ops` uses a mutable tag without a digest
- [ ] A decision is recorded on infra-image scanning (Dependabot only, or Dependabot plus a scheduled scan)
- [ ] `infra/README.md` in the new repo still explains *why* these files are committed — that rationale must not be lost in the move

## Blocked by

`issues/01-stand-up-ops-repo.md`

## Blocks

`issues/04-remove-ops-paths.md` — do not delete `infra/` from the public repo until
the private repo is demonstrably watching those images.
