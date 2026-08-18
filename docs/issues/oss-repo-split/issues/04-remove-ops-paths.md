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
- [ ] It is understood and accepted that this removes the files from `HEAD` only — the content remains in public git history (see PRD, "Why no history rewrite")

## Blocked by

- `issues/01-stand-up-ops-repo.md` — content must exist elsewhere first
- `issues/02-restore-dependabot-coverage.md` — do not drop `infra/` until CVE watching is live in the private repo
- `issues/03-de-tenant-deployment-surface.md` — determines what stays
