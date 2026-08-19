# Remove deployment-specific paths from the public repo

Status: ready-for-human
Category: chore
Type: human

## Parent

`docs/issues/oss-repo-split/PRD.md`

## Pre-flight: re-run the secret scan

**Do this first, in the same sitting as the deletion.** The decision not to rewrite
history (see the PRD) rests on a scan taken on 2026-08-18. If a credential was
committed between then and now, deleting the file from `HEAD` does not remove it, and
the whole calculus changes — you would need `filter-repo`, a force push, and to accept
that existing forks keep the content.

Run from the repo root. Prints a verdict per check and exits non-zero on any failure.
Verified against both the passing and failing paths at time of writing.

**Save it to a file and run the file — do not paste this block into a terminal.**
The fence markers are backticks, so pasting from the opening fence onward starts an
unterminated command substitution: the shell consumes the script as a string instead
of executing it, and can be left with its stdout detached from the terminal. The
symptom is a session where the prompt still appears but no command prints anything
(bash writes the prompt to stderr). Fix with `exec 1>/dev/tty`, or open a new shell.

```bash
#!/bin/bash
# Pre-flight secret scan. Must be clean before the deletion in issue 04.
# Run from the repo root. Prints a verdict per check; exits non-zero on any failure.
set -u

root=$(git rev-parse --show-toplevel 2>/dev/null) || {
  echo "ABORT: not inside a git repository - cd to the repo root first"; exit 2; }
[ -f "$root/composer.json" ] && [ -f "$root/docker-compose.yml" ] || {
  echo "ABORT: $root does not look like the polybag repo"; exit 2; }
cd "$root" || exit 2
echo "Scanning $root"
echo

fail=0
chk() {
  local name="$1" hits="$2"
  if [ -n "$hits" ]; then
    printf '  FAIL  %s\n' "$name"; printf '%s\n' "$hits" | sed 's/^/          /'; fail=1
  else
    printf '  ok    %s\n' "$name"
  fi
}

chk "1. credential-shaped values at HEAD" "$(
  git grep -InIE "(PASSWORD|SECRET|TOKEN|API_KEY|ACCESS_KEY)=['\"]?[A-Za-z0-9/+_-]{16,}" \
    -- docs scripts infra .env.example 2>/dev/null \
  | grep -viE "your-|xxx|example|placeholder|CHANGEME|<|\\\$\\{|\\\$\\(" )"

chk "2. credential-shaped values in history" "$(
  git log --all -p --diff-filter=AM -- docs scripts infra .env.example 2>/dev/null \
  | grep -E "^\+" \
  | grep -IiE "(password|secret|token|api_key|access_key|private_key)" \
  | grep -oE "[A-Za-z_]+=['\"]?[A-Za-z0-9/+]{24,}" | sort -u )"

# public/qz-certificate.pem is allowlisted: PUBLIC cert, CN=shipping-native.test,
# removed in cb9541e. It is a permanent hit; without this the check never passes.
chk "3. sensitive filenames ever committed" "$(
  git log --all --pretty=format: --name-only --diff-filter=A 2>/dev/null \
  | sort -u | grep -iE "\.env$|\.env\.(prod|production|local)$|\.pem$|\.key$|\.p12$|id_rsa" \
  | grep -v example | grep -v "^public/qz-certificate.pem$" )"

chk "4. .env ever tracked" "$(git log --all --oneline -- .env .env.production 2>/dev/null)"

# RFC1918 ranges are deliberately not checked: they appear as generic placeholders in
# installer prompts and fail2ban ignoreip lists, and identify nothing. Only the
# tailnet CGNAT range would actually leak our infrastructure.
chk "5. tailnet (100.64/10) IPs" "$(
  git grep -InIE "\b100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.[0-9]+\.[0-9]+\b" \
  -- docs scripts infra .env.example .github 2>/dev/null )"

chk "6. security-reports/ ever tracked" "$(git ls-files security-reports)"

echo
if [ "$fail" -eq 0 ]; then
  echo "CLEAN - safe to proceed with the deletion in issue 04."
else
  echo "NOT CLEAN - reassess the no-history-rewrite decision before deleting anything."
  exit 1
fi
```

If it does not print `clean`, **stop** and reassess before deleting anything.

## What to build

The deletion commit. Remove the Tier 1 paths listed in the PRD from `niolson/polybag`,
now that `polybag-ops` holds them (issue 01) and is watching the infra images
(issue 02).

Add a short pointer to the public `README.md` — one or two sentences saying that
hosted-deployment and server-operations tooling lives in a separate private repo, so
the absence is intentional rather than an oversight. Do not name internal paths or the
server.

Then sweep for references that now dangle:

- `README.md` and `CLAUDE.md` both link to `docs/server-setup.md`
- `docs/qz-tray-provisioning.md` and `scripts/qz-provision/` reference provisioning flow
- `infra/README.md` describes directories that no longer exist
- `composer.json` / `package.json` scripts, if any point at moved scripts

## Acceptance criteria

- [ ] The pre-flight scan above was re-run in this sitting and printed `clean`
- [ ] Every Tier 1 path from the PRD is gone from the public repo's `HEAD`
- [ ] `infra/shared/*.cnf` and `scripts/install-onprem.sh` are still present
- [ ] `grep -rIn "server-setup.md\|provision-tenant.sh\|cloudflare-hardening" ` over the public repo returns no live references (git history excluded)
- [ ] The test suite passes and the standalone compose smoke test from issue 03 still succeeds after the deletion
- [ ] `README.md` carries the pointer note
- [ ] `polybag-ops` holds `infra/shared/*.cnf` and `infra/gotenberg/`, and its `infra/README.md` deploy steps match
- [ ] The ops deploy procedure asserts encryption at rest, mirroring the check in `scripts/install-onprem.sh`
- [ ] The four unlisted ops paths in the prerequisites section are deleted along with Tier 1
- [ ] `infra/README.md` in the public repo is rewritten or replaced — issue 03 deferred this here, and after the deletion it describes four `/opt/*` directories, three of which are gone
- [ ] It is understood and accepted that this removes the files from `HEAD` only — the content remains in public git history (see PRD, "Why no history rewrite")

## Blocked by

- `issues/01-stand-up-ops-repo.md` — content must exist elsewhere first
- `issues/02-restore-dependabot-coverage.md` — do not drop `infra/` until CVE watching is live in the private repo
- `issues/03-de-tenant-deployment-surface.md` — determines what stays

---

## Blocker found while doing issue 02 (2026-08-18)

**Deleting `infra/shared/docker-compose.yml` from the public repo leaves `/opt/shared`
deployable from neither repo.** Resolve this before the deletion; it is not covered by
the pre-flight secret scan above.

`infra/shared/docker-compose.yml` mounts three sibling files by relative path:

```yaml
- ./mysql.cnf:/etc/mysql/conf.d/encryption.cnf:ro
- ./mysqld.my:/usr/sbin/mysqld.my:ro
- ./component_keyring_file.cnf:/usr/lib64/mysql/plugin/component_keyring_file.cnf:ro
```

Issue 01 moved the compose file to `polybag-ops` and — correctly, per the PRD — left
the three `.cnf` files public, because the root `docker-compose.yml` mounts them for
the standalone self-host path. The result after this issue's deletion:

| Repo | Has the compose file | Has the `.cnf` files |
| --- | --- | --- |
| `polybag` (public) | no (deleted here) | yes |
| `polybag-ops` (private) | yes | **no** |

So `cp infra/shared/* /opt/shared/` — the documented procedure in `infra/README.md` —
cannot be run from either checkout alone. Right now it still works only because the
public repo happens to hold all four files; this issue is what breaks it.

The failure mode is worse than a clean error. Docker creates a **directory** at a
bind-mount source that does not exist, so MySQL would start with a directory mounted
over `mysqld.my` and its keyring component config, rather than refusing to boot. On a
server using TDE that risks an encrypted-tablespace failure at startup, and the
keyring migration in `docs/server-setup.md` is exactly the hard-won procedure this
would put at risk.

Likely resolution: copy the three `.cnf` files into `polybag-ops/infra/shared/` so
that directory is self-contained, and accept that they exist in both repos serving two
different consumers — the public copies for the standalone path in the root
`docker-compose.yml`, the private copies for the shared stack. They contain no
secrets. Whichever way it is decided, `infra/README.md` in `polybag-ops` needs its
deploy instructions updated to match.

---

## Prerequisites in `polybag-ops` (verified 2026-08-19)

Inspected a fresh clone of `polybag-ops`. Two paths the public repo is about to delete
do not exist there yet, so deleting them here makes each deployable from neither
checkout. **All four items below must land in `polybag-ops` before the deletion commit.**

Current `polybag-ops/infra/`: `shared/docker-compose.yml` (byte-identical to the public
copy), `caddy/`, `uptime-kuma/`, `.env.example`, `backup.env.example`,
`shared-secrets.env.example`.

### 1. The three `.cnf` files are absent from `polybag-ops`

Already described above. Worth noting the procedure is *already* broken in the private
repo: `polybag-ops/infra/README.md:36` documents

```
cp infra/shared/* /opt/shared/          # excludes .env, which is server-only
```

which copies four files from a public checkout and exactly one from an ops checkout.
This issue does not create that problem, it removes the last checkout where the command
still works.

### 2. `infra/gotenberg/` is absent from `polybag-ops`

Issue 03 settled that `infra/gotenberg/docker-compose.yml` — the `container_name:
gotenberg` singleton on the external `shared` network — moves to `polybag-ops`, while
the self-hoster's Gotenberg stays in `docker-compose.onprem.yml`. The decision is
recorded; the move was never executed. Same failure shape as the `.cnf` files, without
the partial-copy subtlety: delete it here and the shared-server Gotenberg is deployable
from nowhere.

### 3. Add an encryption assertion to the ops deploy procedure

Duplicating the `.cnf` files means a change to encryption config has to land in both
repos. That is a real but small risk — the three files total 31 lines and have changed
twice ever (`4ddaaca`, `3e9903e`) — and the two copies may legitimately diverge later,
since one serves a single-tenant standalone MySQL and the other a shared multi-tenant
one.

The consequence, however, is severe and silent. `infra/shared/mysql.cnf` says so itself:
if the config does not land correctly "the whole conf.d include is skipped silently and
you get a server that starts fine with no encryption at all."

So mitigate by detection rather than by trying to prevent drift.
`scripts/install-onprem.sh` already asserts `default_table_encryption=ON` and a keyring
component status of `Active` after install; the `/opt/shared` procedure has no
equivalent. Port that assertion into the ops deploy steps. It catches a stale copy at
the moment it matters, which is more reliable than remembering to sync two files that
change once every two years.

Rejected alternative: having `polybag-ops` fetch the `.cnf` files from the public repo
at deploy time. It removes drift, but adds a network dependency to a procedure you run
precisely when things are already broken.

### 4. Four ops files are missing from the PRD's Tier 1 list

The Tier 1 table was assembled by category and skipped these. Two of them are already
in `polybag-ops`, so the public copies are pure duplication with no plan to remove them:

| Path | Why it is ours | In `polybag-ops`? |
| --- | --- | --- |
| `infra/.env.example` | "Copy to `/opt/shared/.env`" — shared datastore passwords | yes |
| `infra/backup.env.example` | S3 backup credentials, `S3_BUCKET=polybag` | yes |
| `scripts/lib/backup-keys.test.sh` | tests `backup-keys.sh`, which is Tier 1 | — |
| `scripts/lib/env-list.sh` + `env-list.test.sh` | only consumer is `rotate-internal-secrets.sh`, which is Tier 1 | — |

Leaving the last three behind orphans a test suite against deleted code.

Staying, correctly: `scripts/backup-local-db.sh` and `scripts/restore-local-db.sh` are
local development helpers that dump to `storage/app/private/db-backups/`, unrelated to
the server.
