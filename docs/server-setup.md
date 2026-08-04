# Server Setup

One-time setup for a VPS that will host PolyBag tenant instances.

## Requirements

- Ubuntu 24.04 LTS (recommended)
- 2 GB RAM minimum (shared infra), 4+ GB recommended for multiple tenants
- SSH access as root
- A domain with wildcard DNS pointing to the server (e.g. `*.polybag.app` -> server IP)

## Resource Estimates

| Component | RAM |
|---|---|
| Shared MySQL | ~300 MB |
| Shared Redis | ~16 MB (256 MB max) |
| Each tenant (app + nginx + queue) | ~120 MB |
| Caddy | ~30 MB |

With shared infra on a 2 GB server, you can comfortably run 8-10 tenants.

## 1. System Updates

```bash
apt update && apt upgrade -y
```

Reboot if the kernel was updated:

```bash
reboot
```

## 2. Firewall

If using a cloud provider firewall (Hetzner, DigitalOcean, etc.), allow inbound on:

- **22/tcp** (SSH)
- **80/tcp** (HTTP)
- **443/tcp** (HTTPS)
- **443/udp** (HTTP/3 / QUIC)

Block everything else.

## 3. Install Docker

```bash
curl -fsSL https://get.docker.com | sh
docker compose version  # verify
```

## 4. Create Docker Networks

```bash
docker network create proxy   # Caddy <-> nginx routing
docker network create shared  # Tenant app <-> shared MySQL/Redis
```

## 5. Set Up Caddy (Reverse Proxy)

Caddy runs as a Docker container on the `proxy` network, handling TLS and routing subdomains to tenant containers.

```bash
mkdir -p /opt/caddy
```

Create `/opt/caddy/Caddyfile`:

```
# Tenant entries are added by the provisioning script.
# Each entry maps a subdomain to a tenant's nginx container.
#
# Example:
# acme.polybag.app {
#     reverse_proxy acme-nginx-1:80
# }
```

Copy in the compose file — it is tracked in the repo so its image pin is kept
current by Dependabot (see `infra/README.md`):

```bash
cp <repo>/infra/caddy/docker-compose.yml /opt/caddy/docker-compose.yml
```

Start Caddy:

```bash
cd /opt/caddy && docker compose up -d
```

## 6. Shared Infrastructure Setup

Shared MySQL + Redis serve all tenants from a single instance, reducing memory usage significantly.

```bash
mkdir -p /opt/shared
cp <repo>/infra/shared/* /opt/shared/
chmod 644 /opt/shared/mysql.cnf /opt/shared/mysqld.my /opt/shared/component_keyring_file.cnf
cp <repo>/infra/.env.example /opt/shared/.env
cp <repo>/infra/shared-secrets.env.example /opt/shared/shared-secrets.env
```

`infra/shared/` carries the compose file plus the three MySQL config files
that encryption at rest depends on — see the section below.

**The `chmod 644` is not optional.** `mysqld` runs as the `mysql` user, and if
it cannot read a file in `conf.d` it does not fail — it silently stops
processing the entire include directory and starts with **no encryption at
all**. `cp` preserves the source permissions, so a checkout with a restrictive
umask produces exactly that. Verify with the query at the end of the
encryption section rather than assuming.

Edit `/opt/shared/.env` and set strong passwords:

```bash
cd /opt/shared
nano .env   # set MYSQL_ROOT_PASSWORD and REDIS_PASSWORD
```

Edit `/opt/shared/shared-secrets.env` — this file is injected into every tenant app container at runtime, so secrets here are set once and apply to all tenants. `REDIS_PASSWORD` must match the value in `.env`:

```bash
nano /opt/shared/shared-secrets.env  # set REDIS_PASSWORD, GOOGLE_CLIENT_ID/SECRET, OAUTH_BROKER_SECRET, RESEND_API_KEY
```

To rotate any shared secret later: update `shared-secrets.env` and restart all tenant containers (`deploy-tenant.sh --all`). No per-tenant `.env` edits needed.

Start shared infrastructure:

```bash
cd /opt/shared && docker compose up -d
```

Verify:

```bash
docker exec shared-mysql mysqladmin ping -h localhost
docker exec shared-redis redis-cli -a <password> ping
```

### Encryption at Rest (TDE)

Shared MySQL uses InnoDB tablespace encryption plus binary log encryption. Three files work together, all installed by the copy step above:

| File | Mounted to | Role |
|---|---|---|
| `mysql.cnf` | `/etc/mysql/conf.d/encryption.cnf` | `default_table_encryption=ON`, `binlog_encryption=ON` |
| `mysqld.my` | `/usr/sbin/mysqld.my` | manifest that activates the keyring component |
| `component_keyring_file.cnf` | `/usr/lib64/mysql/plugin/…` | sets the key file path |

The keys come from the keyring **component**. Do not reintroduce the older
`early-plugin-load=keyring_file.so` plugin form found in pre-8.4 setups: that
plugin was **removed in MySQL 8.4**, and with it the server refuses to start
("Can't open shared library 'keyring_file.so' … Aborting").

**How it works:**
- Data files on disk are encrypted with AES — queries, indexes, and search work normally (decrypted transparently at the query layer)
- The keyring file is stored in a separate Docker volume (`mysql-keyring`) from the data volume (`mysql-data`)
- Each tenant's `db:encrypt-tables` command runs on startup to encrypt any pre-existing unencrypted tables

**Verify it is actually on.** Both silent-failure modes above produce a
perfectly healthy-looking server, so check rather than assume:

```bash
# Standalone: substitute the `mysql` service container for `shared-mysql`.
docker exec shared-mysql mysql -uroot -p<password> -e "
  SHOW VARIABLES LIKE 'default_table_encryption';
  SHOW VARIABLES LIKE 'binlog_encryption';
  SELECT STATUS_VALUE FROM performance_schema.keyring_component_status
   WHERE STATUS_KEY='Component_status';"
```

Expect `ON`, `ON`, and `Active`. Anything else means encryption is not in
effect regardless of what the config files say.

#### Migrating an existing encrypted install from the keyring plugin

Only relevant for a server whose data was encrypted under the **old
`keyring_file` plugin** — an install predating the MySQL 8.4 pin. If
`/var/lib/mysql-keyring/keyring` is empty or absent, there is nothing to
migrate and you can ignore this.

The keys live in the plugin's keystore. Switching to the component without
moving them leaves MySQL running against a keyring that has no keys in it,
unable to decrypt existing tables. **Order matters, and getting it wrong is
unrecoverable.**

Note `mysql_migrate_keyring` is **not** the tool for this — it migrates between
keyring *components* and explicitly does not support migrations involving
plugins. Plugin-to-component uses a one-off `mysqld` in migration mode.

**This applies to both deployment modes.** The keyring guard in the standalone
`docker-compose.yml` and the one in `infra/shared/docker-compose.yml` both send
you here. The two differ only in which container holds the data and where the
keyring config files live, so the procedure is written against those two
values — set them once, per the mode you are running, and the rest is
identical:

| | Shared server | Standalone / on-prem |
|---|---|---|
| `MYSQL_CONTAINER` | `shared-mysql` | the `mysql` service container (resolved below) |
| `CONF_DIR` | `/opt/shared` | `infra/shared` in the repo checkout |

**1. Back up the `mysql-keyring` volume and the database before anything
else.** Everything below operates on the only copy of your keys.

**2. Migrate while still on MySQL 8.0.** `keyring_file.so` does not ship in
8.4, so after upgrading the old keystore cannot be read at all and the
migration becomes impossible. Stop the server, then run a one-off migration
server — it accepts no connections, migrates the keys, and exits:

```bash
# Pick ONE of these two pairs, matching your deployment.

# Shared server:
MYSQL_CONTAINER=shared-mysql
CONF_DIR=/opt/shared

# Standalone / on-prem — run from the repo checkout. Resolving the container
# by service name avoids having to guess the Compose project prefix, and -a
# finds it even though you have just stopped it:
# MYSQL_CONTAINER=$(docker compose --profile standalone \
#   -f docker-compose.yml -f docker-compose.onprem.yml ps -aq mysql)
# CONF_DIR=$(pwd)/infra/shared

# Both volume paths come from the container itself rather than from hardcoded
# volume names, which differ per deployment (the Compose project prefix is the
# checkout directory name for standalone, `shared_` on the shared server).
KEYRING=$(docker inspect "$MYSQL_CONTAINER" \
  --format '{{ range .Mounts }}{{ if eq .Destination "/var/lib/mysql-keyring" }}{{ .Source }}{{ end }}{{ end }}')
DATA=$(docker inspect "$MYSQL_CONTAINER" \
  --format '{{ range .Mounts }}{{ if eq .Destination "/var/lib/mysql" }}{{ .Source }}{{ end }}{{ end }}')

# Capture the EXACT image this data directory has been running under, and use
# it for both the migration and the verification below. Do NOT write a bare
# `mysql:8.0` anywhere in this procedure: that resolves to the latest 8.0
# point release, and starting a newer server against an existing data
# directory can perform a data dictionary upgrade that cannot be undone —
# while you are mid-migration, with your keys in an unverified state.
#
# Taken from the existing container, this is a local image ID: exact, and no
# pull happens.
MYSQL80=$(docker inspect "$MYSQL_CONTAINER" --format '{{ .Image }}')

# All three must be non-empty, and nothing below may run if they are not —
# an empty $KEYRING or $DATA would hand docker run a garbage bind mount.
# If the container was already removed, none of them resolve: recover the
# volume mountpoints with `docker volume inspect` and take the image from the
# digest pinned in the compose file that was in use (mysql:8.0@sha256:...),
# never the tag, then re-run from the top.
#
# This gates rather than calling `exit`, deliberately: the block is pasted
# into an interactive shell, and `exit` would close it — over SSH, mid-
# migration. The effect is the same, nothing after this point runs.
if [ -z "$KEYRING" ] || [ -z "$DATA" ] || [ -z "$MYSQL80" ]; then
  echo "UNRESOLVED — do not continue:" >&2
  echo "  KEYRING=${KEYRING:-<empty>} DATA=${DATA:-<empty>} MYSQL80=${MYSQL80:-<empty>}" >&2
  false  # leave a nonzero $? behind, without killing an interactive shell
else
  echo "migrating with $MYSQL80"

  # migrate.cnf: keyring_file_data ONLY — see the warning below. Written to a
  # temp file, not CONF_DIR: it is throwaway, and must not be left where the
  # running server might pick it up.
  MIGRATE_CNF=$(mktemp /tmp/keyring-migrate.XXXXXX.cnf)
  printf '[mysqld]\nkeyring_file_data=/var/lib/mysql-keyring/keyring\n' > "$MIGRATE_CNF"
  chmod 644 "$MIGRATE_CNF"

  # Repair destination ownership first. This command bypasses the image
  # entrypoint and runs as UID 999, so it cannot write a component_keyring_file
  # left behind root-owned by an earlier failed startup — the very failure this
  # setup fixes. Ownership only: never delete or truncate either file, as one of
  # them may hold the sole copy of your keys.
  chown 999:999 "$KEYRING"
  [ -e "$KEYRING/component_keyring_file" ] && chown 999:999 "$KEYRING/component_keyring_file"

  docker run --rm --user 999:999 --entrypoint mysqld \
    -v "$DATA":/var/lib/mysql \
    -v "$KEYRING":/var/lib/mysql-keyring \
    -v "$MIGRATE_CNF":/etc/mysql/conf.d/migrate.cnf:ro \
    -v "$CONF_DIR"/component_keyring_file.cnf:/usr/lib64/mysql/plugin/component_keyring_file.cnf:ro \
    "$MYSQL80" \
    --keyring-migration-to-component \
    --keyring-migration-source=keyring_file.so \
    --keyring-migration-destination=component_keyring_file.so
fi
```

Two things must be **absent** from that command, both of which cause failures
that are easy to misread:

- **No `early-plugin-load=keyring_file.so`.** The migration server must not
  start with a keyring of its own — it loads the source and destination itself
  from the `--keyring-migration-*` options. `keyring_file_data` is still
  required, so the source keystore can be found, which is why `migrate.cnf`
  above carries that and nothing else. Do not reuse the running server's
  config file here.
- **No `mysqld.my`.** With the component manifest mounted, migration fails
  with `Cannot load component from specified URN`.

A successful run prints no errors and leaves a non-empty
`component_keyring_file`. A failed one reports `Failed to initialize
destination keyring` — and still leaves a `component_keyring_file` behind, so
never treat that file's existence as success.

**3. Verify while still on 8.0, before renaming anything.** Start 8.0 again
with the *component* configuration — manifest and component config, no plugin
config — and read an encrypted table:

Run this as a script rather than pasting line by line — it exits non-zero on
failure, and that is the point:

```bash
#!/bin/bash
set -euo pipefail

# Passed in, not inherited: step 2's values were set in an interactive shell
# and are not exported into this script.
#
#   Shared server:  ./verify-keyring.sh shared-mysql /opt/shared
#   Standalone:     ./verify-keyring.sh \
#                     "$(docker compose --profile standalone \
#                        -f docker-compose.yml -f docker-compose.onprem.yml \
#                        ps -aq mysql)" \
#                     "$(pwd)/infra/shared"
MYSQL_CONTAINER=${1:?usage: $0 <mysql-container> <conf-dir>}
CONF_DIR=${2:?usage: $0 <mysql-container> <conf-dir>}

# `|| true` so a missing container falls through to the check below with a
# useful message, rather than dying on the bare `docker inspect` failure.
KEYRING=$(docker inspect "$MYSQL_CONTAINER" \
  --format '{{ range .Mounts }}{{ if eq .Destination "/var/lib/mysql-keyring" }}{{ .Source }}{{ end }}{{ end }}') || true
DATA=$(docker inspect "$MYSQL_CONTAINER" \
  --format '{{ range .Mounts }}{{ if eq .Destination "/var/lib/mysql" }}{{ .Source }}{{ end }}{{ end }}') || true

# Must be the same image step 2 migrated with — same container, so it is.
MYSQL80=$(docker inspect "$MYSQL_CONTAINER" --format '{{ .Image }}') || true

if [ -z "$KEYRING" ] || [ -z "$DATA" ] || [ -z "$MYSQL80" ]; then
  echo "could not resolve volumes or image from $MYSQL_CONTAINER" >&2
  exit 1
fi
echo "verifying with $MYSQL80"

# Refuse to run alongside a leftover verifier. A previous failed attempt
# leaves one behind on purpose (see below), so this is a state you will
# actually hit — and the consequence is severe: `docker run` would fail on the
# name clash while every query afterwards silently hit the STALE container.
# That container may hold another deployment's data, or this one's from before
# the migration, and either way it can report a healthy keyring and real rows.
# Believing that output is precisely how an unmigrated keyring gets archived.
#
# Not auto-removed: its log is the evidence the previous run preserved for
# you. Read it, then remove it by hand.
if docker inspect keyring-verify >/dev/null 2>&1; then
  echo "keyring-verify already exists — a previous run left it for inspection." >&2
  echo "Read its log, then remove it and re-run:" >&2
  echo "  docker logs keyring-verify" >&2
  echo "  docker rm -f keyring-verify" >&2
  exit 1
fi

# Deliberately NOT --rm: if the server fails to start, the container has to
# survive so its log can be read. A vanished container is the one case where
# you most need the evidence.
docker run -d --name keyring-verify -e MYSQL_ROOT_PASSWORD=<password> \
  -v "$DATA":/var/lib/mysql \
  -v "$KEYRING":/var/lib/mysql-keyring \
  -v "$CONF_DIR"/mysql.cnf:/etc/mysql/conf.d/encryption.cnf:ro \
  -v "$CONF_DIR"/mysqld.my:/usr/sbin/mysqld.my:ro \
  -v "$CONF_DIR"/component_keyring_file.cnf:/usr/lib64/mysql/plugin/component_keyring_file.cnf:ro \
  "$MYSQL80"

# The container is detached and MySQL takes tens of seconds to accept
# connections, longer on a first start. Querying immediately just fails to
# connect, which is easy to misread as a failed migration.
ready=0
for i in $(seq 1 60); do
  if docker exec keyring-verify mysqladmin ping -uroot -p<password> --silent >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 2
done

if [ "$ready" -ne 1 ]; then
  echo "keyring-verify never became ready — leaving it in place for inspection." >&2
  # `|| true`: under pipefail a failing docker logs would abort here and skip
  # the warning below, which is the line that actually matters.
  docker logs keyring-verify 2>&1 | tail -40 || true
  echo "Do NOT rename the legacy keyring. Investigate, then re-run." >&2
  exit 1
fi

docker exec keyring-verify mysql -uroot -p<password> \
  -e "SELECT STATUS_VALUE FROM performance_schema.keyring_component_status
       WHERE STATUS_KEY='Component_status';
      SELECT * FROM <some_encrypted_table> LIMIT 1;"
```

Expect `Active` and real rows. **Read that output yourself** — the query
succeeding is not the check; the component being `Active` and the rows being
real is. Only once you have seen both, clean up:

```bash
docker rm -f keyring-verify
```

If the server never became ready, the container is still there on purpose:
read its log before concluding anything about the migration, because a startup
problem and a failed migration look nothing alike, and only one of them means
your keys are in trouble. Remove it before re-running — the script refuses to
start while one exists, rather than risk querying a stale container and
reporting a healthy keyring that belongs to some earlier attempt. This step
deliberately runs 8.0 by hand rather
than through Compose: the legacy keyring is still in place at this point, so
the keyring-init service (`shared-mysql-keyring-init`, or `mysql-keyring-init`
standalone) would refuse to start the Compose-managed server. Verify first,
rename second — not the other way round.

**4. Archive the legacy keyring.** Only once step 3 returns rows — run this in
the same shell as step 2, which still has `$KEYRING`:

```bash
mv "$KEYRING/keyring" "$KEYRING/keyring.migrated"
```

Keep the file — do not delete it. Migration **copies** keys; it does not move
or clear them, so this file still holds a complete set of the old plugin-format
keys, byte-identical to before the migration. After the rename the keyring
directory looks like this:

| File | Contents |
|---|---|
| `component_keyring_file` | the live keys, in component format — **this is what to back up** |
| `keyring.migrated` | the retained plugin-format originals, unchanged |
| `keyring` | absent — the guard keys off this path |

Renaming is how you record that a human verified the migration, and it is
reversible: rename it back if step 3 ever needs repeating. Until it is renamed,
`mysql-keyring-init` refuses to start, because a non-empty legacy keyring may
hold the only copy of your keys and nothing about the component file can prove
otherwise.

**5. Upgrade to 8.4** and bring the stack up normally — `docker compose up -d`
from `/opt/shared`, or the standalone command from the deployment-modes table
in `CLAUDE.md`. Re-run the encrypted table read once more to confirm.

**Keyring backup:**

The keyring file is critical — if lost, encrypted data is unrecoverable. Back it up separately from the database:

```bash
# Find the keyring volume mount
docker volume inspect shared_mysql-keyring --format '{{ .Mountpoint }}'

# Copy the keyring file to a secure backup location (NOT the same location as DB backups)
cp /var/lib/docker/volumes/shared_mysql-keyring/_data/component_keyring_file /path/to/secure/backup/
```

Note the filename: the component writes `component_keyring_file`. Older
plugin-era installs also have an empty `keyring` file next to it, which is a
leftover and is not the key material. `backup-nightly.sh` already copies the
correct one.

Back up the keyring after initial setup and after any key rotation. Store it in a different location from your database backups (different cloud account, different physical location, or a password manager vault).

**Existing servers:** If upgrading an existing shared-mysql instance, restart it after adding the config:

```bash
cd /opt/shared && docker compose up -d
```

Then each tenant's next deploy will run `db:encrypt-tables` to encrypt existing tables.

### Gotenberg (PDF Rendering)

Renders pack slips and labels for every tenant.

```bash
mkdir -p /opt/gotenberg
cp <repo>/infra/gotenberg/docker-compose.yml /opt/gotenberg/docker-compose.yml
cd /opt/gotenberg && docker compose up -d
/opt/tenants/<any-tenant>/scripts/reconnect-shared-networks.sh
```

That last step is required. Compose attaches Gotenberg only to the `shared`
network, but tenants reach it over their own `shared-<tenant>` networks — so
every recreation drops those attachments until they are restored. The script is
idempotent. The same applies to shared MySQL and Redis.

Verify from a tenant app container:

```bash
docker exec <tenant>-app-1 curl -s -o /dev/null -w '%{http_code}\n' http://gotenberg:3000/health
```

### Uptime Kuma (Monitoring)

```bash
mkdir -p /opt/uptime-kuma
cp <repo>/infra/uptime-kuma/docker-compose.yml /opt/uptime-kuma/docker-compose.yml
cd /opt/uptime-kuma && docker compose up -d
```

Runs v2. Upgrading from the v1 tag is a one-way migration that rewrites
heartbeat history in place — back up the data volume first, and do not
interrupt it:

```bash
docker stop uptime-kuma
tar -czf /var/backups/uptime-kuma-$(date -u +%Y%m%d).tar.gz \
  -C "$(docker volume inspect uptime-kuma_data --format '{{.Mountpoint}}')" .
# then bump the pin and `docker compose up -d`, watching `docker logs -f`
```

The migration logs `[DON'T STOP]` while aggregating heartbeats and takes
minutes to hours depending on history size. An interrupted run must be
restored from backup and retried. Note the server starts listening *during*
migration, so a reachable web UI is not the completion signal — wait for the
progress lines to finish.

Expect this image to dominate the monthly scan report: v2 bundles Chromium
for browser-based monitors. See the comment in
`infra/uptime-kuma/docker-compose.yml` for why that is still an improvement
over v1.

## 7. Create Tenants Directory

```bash
mkdir -p /opt/tenants
```

## 8. Database Backups

Automated daily backups to S3-compatible storage (Hetzner Object Storage, AWS S3, etc.).

### Install AWS CLI

```bash
apt install -y awscli
```

### Configure credentials

```bash
cp <repo>/infra/backup.env.example /opt/shared/backup.env
nano /opt/shared/backup.env  # set S3_ACCESS_KEY, S3_SECRET_KEY
```

### Test the backup

```bash
/opt/tenants/<any-tenant>/scripts/backup-db.sh
```

This will dump all `polybag_*` databases, compress them, and upload to the S3 bucket. You can also back up a single database:

```bash
/opt/tenants/<any-tenant>/scripts/backup-db.sh polybag_acme
```

### Schedule daily backups

```bash
crontab -e
# Add:
0 3 * * * /opt/tenants/<any-tenant>/scripts/backup-db.sh >> /var/log/polybag-backup.log 2>&1
```

Runs daily at 03:00 UTC. Old backups are automatically pruned after `BACKUP_RETENTION_DAYS` (default 30).

### Keyring backup

The MySQL keyring must also be backed up — without it, encrypted data is
unrecoverable. `scripts/backup-nightly.sh` already does this as part of the
nightly run, so no separate crontab entry is needed; prefer that single entry
over scheduling the database and keyring backups separately.

If you do copy the keyring by hand, take **`component_keyring_file`**:

```bash
docker cp shared-mysql:/var/lib/mysql-keyring/component_keyring_file /tmp/keyring
```

Not `/var/lib/mysql-keyring/keyring`. Whatever that path holds, it is not the
keys MySQL is currently using, and backing it up produces an archive that looks
fine and restores nothing. What you find there depends on the server's history:

| File present | Meaning |
|---|---|
| `keyring`, 0 bytes | a plugin was once configured but never held keys — inert |
| `keyring.migrated`, non-empty | plugin-format originals retained after a migration — a historical safety net, not the live keys |
| `keyring`, non-empty | keys never migrated; MySQL should be refusing to start. See the migration section |

Only `component_keyring_file` is live. Confirm before trusting a keyring
backup:

```bash
docker exec shared-mysql ls -l /var/lib/mysql-keyring/
# component_keyring_file must be non-empty — that is the file to archive
```

### Backup encryption

Backups are encrypted with AES-256 before upload. The encryption key is held in a
**versioned keyring** at `/opt/shared/backup-keys.env` so it can be rotated for
compliance without orphaning older backups:

```
BACKUP_KEY_CURRENT=2
BACKUP_KEY_1=<hex>
BACKUP_KEY_2=<hex>
```

Each backup's object name carries a `.kN` tag (e.g. `polybag_acme_2026-03-25_080000.k2.sql.gz.enc`)
recording which key version decrypts it. If no keyring exists, `backup-db.sh` falls
back to the legacy single `BACKUP_ENCRYPTION_KEY` in `backup.env` and writes untagged
objects.

**Initialize / rotate** the key with `scripts/rotate-backup-key.sh`. The first run
migrates the legacy key in as `k1` and adds a new current key; subsequent runs add the
next version. Old keys are never deleted automatically:

```bash
/opt/tenants/<any-tenant>/scripts/rotate-backup-key.sh            # rotate (annual / on compromise)
/opt/tenants/<any-tenant>/scripts/rotate-backup-key.sh --retire   # drop keys no backup still needs
```

> **Escrow the keyring off-server** (secrets manager / offline). If it is lost, every
> encrypted backup in S3 is unrecoverable; if it lives only beside the backups, encryption
> adds little protection. Retire an old key only after its tagged backups have aged out of
> the retention window (`--retire` refuses while any still reference it).

### Restore from backup

`scripts/restore-db.sh` downloads a backup, selects the right key from its `.kN` tag,
decrypts/decompresses, and loads it into a target database (restore into a scratch
database to verify backups without touching production):

```bash
# List available backups
/opt/tenants/<any-tenant>/scripts/restore-db.sh --list

# Restore into a scratch database (key chosen automatically from the .kN tag)
/opt/tenants/<any-tenant>/scripts/restore-db.sh \
  polybag_acme_2026-03-25_080000.k2.sql.gz.enc --into polybag_acme_restore
```

### Object storage credential rotation

`S3_ACCESS_KEY`/`S3_SECRET_KEY` in `/opt/shared/backup.env` authenticate to the
object storage bucket itself (Hetzner Object Storage), separate from the backup
*encryption* key above. Hetzner has no API for generating or revoking S3 keys —
that step is Console-only — so `scripts/rotate-storage-key.sh` covers everything
around it: it verifies a new key can list, write, and read the real bucket
*before* writing `backup.env`, so a bad paste never breaks the nightly backup.

```bash
# 1. Hetzner Console -> Object Storage -> generate a new access key/secret.
# 2. Run the rotation (prompts for the new key/secret; secret input is hidden):
/opt/tenants/<any-tenant>/scripts/rotate-storage-key.sh

# 3. Confirm end-to-end, then revoke the OLD key shown in the script's output
#    in the Hetzner Console.
/opt/tenants/<any-tenant>/scripts/backup-db.sh
```

Rotate this key on the same cadence as `scripts/rotate-internal-secrets.sh`
(annually, at minimum) or immediately after any suspected credential exposure or
security breach.

> There is currently no automated check anywhere (script or cron) that flags
> when `backup.env`, the backup encryption keyring, or the secrets rotated by
> `rotate-internal-secrets.sh` are overdue for rotation. Track the annual
> schedule externally (calendar reminder / key management document) until such
> a check exists.

## 9. Generate Wildcard QZ Tray Certificate (Optional)

For `*.polybag.app` tenants, a shared QZ Tray signing certificate avoids generating one per tenant:

```bash
mkdir -p /opt/shared/qz
openssl genrsa -out /opt/shared/qz/qz-private-key.pem 2048
openssl req -x509 -new -sha256 -key /opt/shared/qz/qz-private-key.pem \
  -out /opt/shared/qz/qz-certificate.pem -days 3650 \
  -subj "/CN=*.polybag.app"
```

> Keep `-sha256`: QZ Tray rejects SHA-1-signed certificates with an "Invalid
> Certificate" popup.

To pre-trust this certificate on workstations and suppress QZ Tray's Allow/Block
prompt, see [QZ Tray Certificate Provisioning](qz-tray-provisioning.md).

## 10. OAuth Broker (Optional)

The OAuth broker (`connect.<domain>`) handles OAuth authorization code flows on behalf of all PolyBag instances (shared tenants and on-prem). It holds provider client credentials centrally so individual instances don't need them. Skip this if you don't need OAuth integrations.

The broker is a separate Laravel app — see the [polybag-connect](https://github.com/niolson/polybag-connect) repo.

### Generate shared secret

Generate a secret and add it to `/opt/shared/shared-secrets.env` (the file you created during shared infra setup):

```bash
echo "OAUTH_BROKER_SECRET=$(openssl rand -hex 32)" >> /opt/shared/shared-secrets.env
```

> The provisioning script sets `OAUTH_BROKER_URL` and `OAUTH_INSTANCE_ID` in each tenant's `.env`. `OAUTH_BROKER_SECRET` is injected at runtime from `shared-secrets.env` — no per-tenant edits needed.

### Deploy the broker

```bash
git clone https://github.com/niolson/polybag-connect.git /opt/polybag-connect
cd /opt/polybag-connect
cp .env.example .env
```

Edit `/opt/polybag-connect/.env`:
- Set `SHARED_TENANT_SECRET` to the same value as `OAUTH_BROKER_SECRET` in `/opt/shared/shared-secrets.env`
- Set `REDIS_HOST=shared-redis` and `REDIS_PASSWORD` (from `/opt/shared/.env`)
- Add provider credentials (`SHOPIFY_CLIENT_ID`, `SHOPIFY_CLIENT_SECRET`, etc.)

Build and start:

```bash
cd /opt/polybag-connect && docker compose up -d --build
```

### Register on-prem instances

On-prem instances need individual secrets. Register them from inside the broker container:

```bash
docker exec -it polybag-connect-app-1 php artisan instance:register <instance-id>
```

This outputs a secret to give to the on-prem customer for their `.env`.

### Add Caddy route

Append to `/opt/caddy/Caddyfile`:

```
connect.polybag.app {
    reverse_proxy polybag-connect-app-1:8080
}
```

Reload Caddy:

```bash
docker compose -f /opt/caddy/docker-compose.yml exec caddy caddy reload --config /etc/caddy/Caddyfile
```

### Verify

```bash
curl -s -o /dev/null -w "%{http_code}" "https://connect.polybag.app/health"
# Should return 200
```

## 11. Provision Tenants

### Shared mode (default)

Uses the shared MySQL + Redis from step 6:

```bash
cd /opt/tenants
/opt/tenants/<any-tenant>/scripts/provision-tenant.sh acme
# or explicitly:
scripts/provision-tenant.sh --mode shared acme
```

The script will:
- Create a dedicated database (`polybag_acme`) and user in shared-mysql
- Set Redis prefix (`acme-`) for key isolation
- Mount QZ certs from `/opt/shared/qz/`

### Standalone mode

Per-tenant MySQL + Redis containers (higher memory, full isolation):

```bash
scripts/provision-tenant.sh --mode standalone acme
```

See `scripts/provision-tenant.sh` for details.

## 12. Security Hardening

One-time hardening applied to the server after initial setup.

### UFW Firewall

Host-level firewall (complements any cloud provider firewall from step 2):

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp    # SSH
ufw allow 80/tcp    # HTTP
ufw allow 443/tcp   # HTTPS
ufw allow 443/udp   # HTTP/3 / QUIC
ufw logging medium
ufw enable
```

> In shared/proxied mode the `:80`/`:443` allows above are only a baseline — to
> actually *restrict* them to Cloudflare, see the next subsection. ufw alone can't
> (Docker publishes those ports and bypasses ufw).

### Cloudflare-only origin lockdown (shared / proxied mode)

When the server sits behind the Cloudflare proxy (orange-cloud — see
`docs/cloudflare-hardening/`), restrict the origin web ports to Cloudflare so the
WAF/proxy can't be bypassed by hitting the origin IP directly.

> **`ufw` cannot do this.** Caddy's `:80`/`:443` are published by Docker, which
> inserts iptables rules that run *before* ufw's INPUT filtering — so `ufw deny` on
> those ports is silently ignored. The restriction must go in the `DOCKER-USER`
> chain instead.

Install the script + self-healing timer from `docs/cloudflare-hardening/option-a/`:

```bash
install -m744 cf-origin-firewall.sh /usr/local/sbin/cf-origin-firewall.sh
install -m644 systemd/cf-origin-firewall.service /etc/systemd/system/
install -m644 systemd/cf-origin-firewall.timer   /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now cf-origin-firewall.timer
systemctl start cf-origin-firewall.service
```

This drops non-Cloudflare traffic to `:80`/`:443` (v4 + v6) via `DOCKER-USER` and
reapplies every 2 min (a Docker daemon restart flushes the chain). SSH (`:22`) is a
host service in the INPUT chain, so plain `ufw` rules apply to it normally (the
Docker-bypass problem above doesn't apply to SSH) — see the next subsection for
restricting it to Tailscale instead of leaving it open to the internet.

Full context — including the Cloudflare-side setup (Origin CA cert + Authenticated
Origin Pulls) — is in `docs/cloudflare-hardening/option-a/README.md`.

### Tailscale-only SSH access (recommended)

Admins with a dynamic IP can't use a static `ufw` allowlist for SSH. Instead, join
the server to your tailnet and restrict `:22` to the `tailscale0` interface —
this closes public SSH entirely while still allowing access from any device on
the tailnet, dynamic IP or not.

```bash
curl -fsSL https://tailscale.com/install.sh | sh
tailscale up --auth-key=<one-time-key-from-the-tailscale-admin-console>
tailscale ip -4   # confirm it got a 100.x.x.x address
```

In the Tailscale admin console, **disable key expiry** for this device — headless
servers can't do the interactive browser re-auth that a normal expiring key
eventually requires, and this server has no other network path in once public
SSH is closed.

**Verify SSH-over-Tailscale works before touching the firewall** — from a device
already on the tailnet: `ssh root@<tailscale-ip>`. Only once that's confirmed:

```bash
ufw allow in on tailscale0 to any port 22 proto tcp
ufw delete allow 22/tcp
```

Verify from *outside* the tailnet that public `:22` now times out, and from a
tailnet device that it still connects. Keep the Hetzner (or provider) web console
as break-glass in case Tailscale itself is ever unreachable — see the root
password note in SSH Hardening below.

### SSH Hardening

Edit `/etc/ssh/sshd_config` (or a file in `/etc/ssh/sshd_config.d/`):

```
PermitRootLogin without-password   # Key-only, no password
PasswordAuthentication no
X11Forwarding no
MaxAuthTries 3
AllowTcpForwarding no
ClientAliveCountMax 2
LogLevel VERBOSE
```

Restart SSH after changes:

```bash
systemctl restart ssh
```

#### Console break-glass

If SSH access is ever restricted to Tailscale (see above), a cloud provider's
web console (e.g. Hetzner Cloud's VNC console) becomes the only recovery path
if the tailnet itself is ever unreachable. Check this actually works *before*
you need it — a fresh cloud image typically ships with the root account
password-**locked** (`passwd -S root` shows `L`), which silently breaks
console login even though it looks like a normal login prompt. Set a real
password if so:

```bash
passwd root   # or: echo "root:$(openssl rand -base64 24 | tr -d '/+=')" | chpasswd
```

This does **not** reopen SSH password auth — `PasswordAuthentication no` blocks
password auth for SSH at the protocol level regardless of whether the account
has a password hash. Store the password in a password manager, not in this
repo or anywhere on the box.

#### Algorithm hardening

OpenSSH's defaults still offer some weak KEX/host-key/MAC options for legacy client compatibility. Restrict to the strong set with a dedicated file in `/etc/ssh/sshd_config.d/` (e.g. `hardening-algorithms.conf`):

```
KexAlgorithms sntrup761x25519-sha512@openssh.com,curve25519-sha256,curve25519-sha256@libssh.org,diffie-hellman-group-exchange-sha256,diffie-hellman-group16-sha512,diffie-hellman-group18-sha512
HostKeyAlgorithms rsa-sha2-512,rsa-sha2-256,ssh-ed25519
Ciphers chacha20-poly1305@openssh.com,aes128-ctr,aes192-ctr,aes256-ctr,aes128-gcm@openssh.com,aes256-gcm@openssh.com
MACs umac-128-etm@openssh.com,hmac-sha2-256-etm@openssh.com,hmac-sha2-512-etm@openssh.com
```

This drops the NIST curves in KEX/host-key (suspected NSA-influenced), SHA-1 MACs, the 2048-bit `diffie-hellman-group14-sha256` KEX, and the non-`-etm` (encrypt-and-MAC) MAC variants — requires OpenSSH 8.5+/Dropbear 2018.76+ on the client, which any modern admin machine satisfies. Validate before restarting:

```bash
sshd -t && systemctl restart ssh
```

Verify with [ssh-audit](https://github.com/jtesta/ssh-audit) (`apt install ssh-audit`) — should show no `[fail]`/`[warn]` entries. **Test a brand-new connection before closing your existing session** — `sshd -t` catches syntax errors but not a config that's valid yet locks out every client you actually have.

### fail2ban

Protects against brute-force attacks by banning IPs after repeated failures.

```bash
apt install -y fail2ban
```

Create `/etc/fail2ban/jail.local`:

```ini
[DEFAULT]
maxretry = 5
findtime = 10m
bantime = 1h

[sshd]
enabled = true
maxretry = 3
bantime = 24h

[nginx-http-auth]
enabled = true

[nginx-botsearch]
enabled = true
```

```bash
systemctl enable --now fail2ban
```

Active jails: `sshd` (3 attempts, 24h ban), `nginx-http-auth`, `nginx-botsearch`. Default: 5 attempts, 1h ban, 10-minute find window.

Config: `/etc/fail2ban/jail.local`

### auditd

Kernel-level audit logging for sensitive file and syscall activity.

```bash
apt install -y auditd
systemctl enable --now auditd
```

Custom rules go in `/etc/audit/rules.d/hardening.rules`. The rules on this server monitor:

- `/etc/passwd`, `/etc/shadow`, `/etc/group`, `/etc/sudoers`, `/etc/ssh/sshd_config`
- Login/logout events
- Docker socket access
- Privilege escalation syscalls (`setuid`, `setgid`, etc.)

### AIDE (File Integrity Monitoring)

Detects unauthorized changes to critical system files.

```bash
apt install -y aide
```

> **Important:** The default AIDE config will OOM this server. Use a minimal custom config at `/etc/aide/aide.conf` that watches critical paths only.

Paths monitored on this server: `/etc/ssh`, `/etc/passwd`, `/etc/shadow`, `/etc/gshadow`, `/etc/group`, `/etc/sudoers`, `/etc/sudoers.d`, `/etc/cron.d`, `/etc/crontab`, `/etc/hosts`, `/etc/resolv.conf`, `/etc/fail2ban`, `/etc/audit`

Initialize the database (always use `nice`/`ionice` on this server — raw `aideinit` will OOM):

```bash
nice -n 19 ionice -c 3 aideinit
cp /var/lib/aide/aide.db.new /var/lib/aide/aide.db
```

Daily check at 3am via `/etc/cron.d/aide-check`, output sent to syslog via `logger -t aide`.

Database: `/var/lib/aide/aide.db`

**Never run `aideinit` without `nice -n 19 ionice -c 3` on this server.**

### Kernel / Network Hardening

Disable unused network protocols and USB storage via `/etc/modprobe.d/disable-unused-protocols.conf`:

```
install dccp /bin/true
install sctp /bin/true
install rds /bin/true
install tipc /bin/true
install usb-storage /bin/true
```

### System Hardening

**Core dumps** — disable in `/etc/security/limits.conf`:

```
* hard core 0
```

**Umask** — tighten to `027` in `/etc/login.defs`:

```
UMASK 027
```

**Additional packages:**

```bash
apt install -y debsums apt-show-versions libpam-tmpdir
```

### Postfix

If Postfix is installed, harden the banner and disable VRFY in `/etc/postfix/main.cf`:

```
smtpd_banner = ESMTP
disable_vrfy_command = yes
```

### Logwatch

Daily log summary written to `/var/log/logwatch/YYYY-MM-DD.log`.

```bash
apt install -y logwatch
```

Config: `/etc/logwatch/conf/logwatch.conf`

Runs at 6am daily via `/etc/cron.d/logwatch`. No mail relay configured — when ready, set `Output = mail` and `MailTo = you@domain.com` in the config.

### Lynis

Weekly security audit.

```bash
apt install -y lynis
```

Weekly audit every Sunday at 2am via `/etc/cron.d/lynis`. Report: `/var/log/lynis-weekly.log`.

### Trivy (Host OS + Container Image Vulnerability Scanning)

CVE scanning for two things CI does not cover:

1. **The host's OS packages, kernel, and system services** (sshd, Caddy, etc.)
   — Grype's CI scan only sees what is baked into an image, not the VPS
   underneath it.
2. **The image behind every running container.** CI runs Grype against
   `polybag-app:ci` and nothing else — not nginx, and none of the
   third-party images (MySQL, Redis, Caddy, Gotenberg, Uptime Kuma,
   polybag-connect). Those are pulled from upstream and never rebuilt, so
   without this they accumulate CVEs indefinitely with nothing watching. It
   also catches the app image *as rebuilt on this host*, which is not the
   artifact CI scanned.

Lynis above audits general hardening posture; this is the CVE-by-severity
scan an auditor asking about "vulnerability scanning" actually wants.

Note the asymmetry in what the two halves produce. Host findings are
dominated by unfixable kernel CVEs — `linux-*` packages where Ubuntu has not
shipped a fix — so the actionable number there is usually zero. Container
image findings are the opposite: overwhelmingly fixable, because they clear
by pulling a current image. That is why reports sort by fixable rather than
by severity.

Install a pinned, checksum-verified release rather than piping an install
script — this runs as root, so we don't trust whatever happens to be
`latest` on scan day. Get the current pin (version + sha256) from
`scripts/security-scan-host.sh` in the app repo; the same two constants are
used here:

```bash
TRIVY_VERSION="0.72.0"
TRIVY_DEB_SHA256="9bf8aba92f524b74f8e83d53b298a7dfc6b4d60aca779217e7817e5433c73eeb"
curl -fsSL -o /tmp/trivy.deb \
  "https://github.com/aquasecurity/trivy/releases/download/v${TRIVY_VERSION}/trivy_${TRIVY_VERSION}_Linux-64bit.deb"
echo "${TRIVY_DEB_SHA256}  /tmp/trivy.deb" | sha256sum -c -
dpkg -i /tmp/trivy.deb && rm -f /tmp/trivy.deb
```

#### Scheduled scan

The scan runs monthly from cron, driven by the same
`scripts/security-scan-host.sh` used for on-demand reports — in `--local`
mode, so what gets scanned is defined in exactly one place rather than
duplicated into a separate wrapper that drifts. It comes from the tenant
checkout, so `deploy-tenant.sh` keeps it current; this is the same
arrangement as the nightly backup cron.

Monthly run, 1st of the month at 4am, via `/etc/cron.d/trivy-host-scan`:

```
SHELL=/bin/bash
PATH=/usr/local/bin:/usr/bin:/bin

0 4 1 * * root /opt/tenants/test/scripts/security-scan-host.sh --local --images --out-parent /var/log/trivy-host-scan --email you@example.com --keep 12 >> /var/log/polybag-security-scan.log 2>&1
```

`--out-parent` has the script build the dated directory name itself. Don't
be tempted to inline a `date +%Y%m%d` in the crontab instead — cron treats a
bare `%` as a newline and would truncate the command at the first one.

`--images` adds the container image half. Images are discovered from
`docker ps` rather than a hand-kept list, so a service added later is
scanned without anyone remembering to update this, and deduplicated by image
ID so a tenant's four app containers are scanned once rather than four
times.

Reports land in `/var/log/trivy-host-scan/host-<host>-<timestamp>/`, matching
the layout of the on-demand reports in `security-reports/`:

| File | Contents |
| --- | --- |
| `host-scan-report.md` | Markdown summary of both halves — the artifact to read or hand to a reviewer |
| `trivy-host-report.json` | Full host findings, every severity |
| `trivy-images-report.json.gz` | Full image findings, every severity, one array entry per image |

The image report is gzipped because it is roughly 14MB raw against 1MB
compressed, and a year of retained reports is where that difference starts
to matter on a 38GB VPS. `--keep 12` prunes all but the twelve most recent.

#### Alerting

`--email` mails the report through Resend when the scan finishes, reusing
the `RESEND_API_KEY` already in `/opt/shared/shared-secrets.env` for the
app's own mail — no second credential to manage. Sender defaults to
`noreply@updates.polybag.app`; override with `SECURITY_SCAN_EMAIL_FROM`.
Comma-separate the value for multiple recipients.

Mail goes out on **every** run, not just when findings appear, and the
verdict is in the subject line:

```
[PolyBag] Host scan clean (no critical/high) on ubuntu-2gb-hil-1
[PolyBag] Host scan: 2 critical / 14 high on ubuntu-2gb-hil-1
[PolyBag] Host vulnerability scan FAILED on ubuntu-2gb-hil-1
```

This is deliberate. If mail only went out on findings, a cron that silently
stopped firing would look identical to a clean scan — the same silent-rot
failure the nightly backup hit once after a password rotation. A scan that
dies partway (trivy crash, full disk, vulnerability DB download failure)
mails the FAILED notice from an exit trap, so a missing monthly message is
itself the signal that something needs looking at.

Fixable findings remediate via `apt-get upgrade` per the standard patching
SLA (Critical: 7 days, High: 30 days). Note that kernel CVEs need a reboot
and a purge of the old kernel packages to actually clear — an `apt upgrade`
alone leaves the vulnerable versions installed and still reported.

#### On-demand

For an audit-ready report (e.g. to hand to a reviewer) rather than waiting
for the monthly cron, run this from a checkout of the app repo:

```bash
./scripts/security-scan-host.sh --ssh root@<server>
```

It installs/verifies the same pinned Trivy remotely, leaves no scanning
tooling behind beyond the trivy binary, and writes a dated Markdown + JSON
report into `security-reports/`, matching the existing ZAP DAST report
convention.

---

## Google SSO Setup

Google SSO allows users to sign in with their Google account instead of a password. All installs (hosted and on-prem) use the same Google Cloud project (`polybag-login`) but separate OAuth client IDs.

### For hosted tenants

The shared client ID is already configured. Just add the tenant's redirect URI:

1. Go to [Google Cloud Console](https://console.cloud.google.com) > APIs & Services > Credentials
2. Click the **polybag-hosted** OAuth client ID
3. Add `https://{tenant}.polybag.app/auth/google/callback` to Authorized redirect URIs
4. Save

The tenant enables SSO via Settings > Single Sign-On > Google SSO toggle.

### For on-prem customers

Create a separate OAuth client ID for each on-prem customer (so the secret stays under your control):

1. Go to Google Cloud Console > APIs & Services > Credentials
2. Click **Create Credentials** > OAuth client ID > Web application
3. Name: customer name (e.g. "Acme Corp")
4. Authorized redirect URI: `https://{customer-domain}/auth/google/callback`
5. Copy the **Client ID** and **Client Secret**

Send the customer:
- `GOOGLE_CLIENT_ID` — the client ID
- `GOOGLE_CLIENT_SECRET` — the client secret

They add these to their `.env` file. SSO is then enabled via Settings.

### Credentials to send on-prem customers

| Credential | Where it goes | How to get it |
|---|---|---|
| `GOOGLE_CLIENT_ID` | `.env` | Create in Google Console (per customer) |
| `GOOGLE_CLIENT_SECRET` | `.env` | Created with the client ID |
| `OAUTH_BROKER_SECRET` | `.env` | From `instance:register` command (if using OAuth broker) |
| `OAUTH_BROKER_URL` | `.env` | `https://connect.polybag.app` (if using OAuth broker) |
| `OAUTH_INSTANCE_ID` | `.env` | Generated during instance registration |

Google SSO and OAuth broker credentials are independent — a customer can use one, both, or neither.

## Mail (Resend) Setup

Transactional email (MFA login codes, etc.) is sent via [Resend](https://resend.com). One Resend account/domain (`updates.polybag.app`) serves all hosted tenants, following the same shared-secret pattern as Google SSO.

### One-time setup (hosted)

1. Sign up at [resend.com](https://resend.com) and add the `updates.polybag.app` domain
2. Add the DNS records Resend gives you (SPF, DKIM, DMARC) to the `updates.polybag.app` zone
3. Create an API key and add it to `/opt/shared/shared-secrets.env`:
   ```bash
   nano /opt/shared/shared-secrets.env  # set RESEND_API_KEY
   ```
4. Restart tenant containers to pick it up: `deploy-tenant.sh --all`

`scripts/provision-tenant.sh` already sets `MAIL_MAILER=resend` and `MAIL_FROM_ADDRESS=noreply@updates.polybag.app` in each tenant's `.env`; the API key itself is injected at runtime from `shared-secrets.env` and never written to a per-tenant `.env` (shared mode) or copied in at provision time if available (standalone mode).

### For on-prem customers

On-prem installs don't get the shared Resend account. Either:
- Point them at their own Resend (or other SMTP) account by setting `MAIL_MAILER`, `RESEND_API_KEY` (or `MAIL_HOST`/`MAIL_USERNAME`/`MAIL_PASSWORD` for plain SMTP), and `MAIL_FROM_ADDRESS` in their `.env`, or
- Leave `MAIL_MAILER=log` (the `.env.example` default) if they don't need MFA email codes — app-based MFA (`AppAuthentication`) still works without mail configured.

## Database Import via SSH Tunnel

When importing shipments from a customer's database that is behind a firewall or not directly accessible, use the built-in SSH tunnel support.

### 1. Generate a keypair on the PolyBag server

```bash
ssh-keygen -t ed25519 -f /opt/ssh-keys/customer-name -N "" -C "polybag-import"
```

Send the **public key** (`/opt/ssh-keys/customer-name.pub`) to the customer.

### 2. Customer setup (their server)

The customer creates a restricted SSH user that can only tunnel to their database:

```bash
# Create a user with no shell access
sudo useradd -m -s /usr/sbin/nologin polybag
sudo mkdir -p /home/polybag/.ssh
sudo chmod 700 /home/polybag/.ssh

# Add the public key with restrictions
echo 'no-pty,no-X11-forwarding,no-agent-forwarding,permitopen="127.0.0.1:3306" ssh-ed25519 AAAA... polybag-import' \
  | sudo tee /home/polybag/.ssh/authorized_keys
sudo chmod 600 /home/polybag/.ssh/authorized_keys
sudo chown -R polybag:polybag /home/polybag/.ssh
```

The `permitopen` directive restricts the tunnel to only forward to the database. Adjust the host and port to match where MySQL/SQL Server/etc. is listening. If the database is on a different host from the SSH server (e.g. a bastion host), use that internal IP instead of `127.0.0.1`.

### 3. Configure the PolyBag instance

Add to the tenant's `.env`:

```bash
# Import database connection
SHIPMENT_IMPORT_DB_DRIVER=mysql
SHIPMENT_IMPORT_DB_HOST=db.customer.internal   # The DB host (used as tunnel remote target)
SHIPMENT_IMPORT_DB_PORT=3306
SHIPMENT_IMPORT_DB_DATABASE=orders
SHIPMENT_IMPORT_DB_USERNAME=readonly_user
SHIPMENT_IMPORT_DB_PASSWORD=secret

# SSH tunnel
SHIPMENT_IMPORT_SSH_ENABLED=true
SHIPMENT_IMPORT_SSH_HOST=bastion.customer.com   # SSH host to connect to
SHIPMENT_IMPORT_SSH_USER=polybag
SHIPMENT_IMPORT_SSH_KEY=/opt/ssh-keys/customer-name

# Optional: override the tunnel's remote side (defaults to DB_HOST:DB_PORT)
# Use when the DB host in DB_HOST is not reachable from the SSH server
# (e.g. DB_HOST is a DNS name that only resolves externally)
# SHIPMENT_IMPORT_SSH_REMOTE_HOST=127.0.0.1
# SHIPMENT_IMPORT_SSH_REMOTE_PORT=3306
```

When `SSH_REMOTE_HOST` is not set, the tunnel forwards to `DB_HOST:DB_PORT` as seen from the SSH server. Set `SSH_REMOTE_HOST=127.0.0.1` when the database runs on the same machine as the SSH server.

### 4. Test the connection

```bash
php artisan shipments:import --dry-run
```

### How it works

The tunnel opens automatically when the import runs, forwards a random local port through SSH to the remote database, and closes when the import finishes. No persistent tunnel or background service is needed.

### Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `Permission denied (publickey,password)` | Key not installed or wrong permissions on remote | Check `authorized_keys` exists, permissions are `600`, owned by the SSH user |
| `Connection refused` on SSH | SSH not running or wrong port | Verify `SHIPMENT_IMPORT_SSH_HOST` and `SSH_PORT` |
| `Cannot connect to import database` | Tunnel opened but DB rejected connection | Check `DB_USERNAME`/`DB_PASSWORD`, and that the DB allows connections from `127.0.0.1` (or whatever `SSH_REMOTE_HOST` is set to) |
| `SSH tunnel timed out` | Firewall blocking SSH or `permitopen` mismatch | Check that the SSH port is open and `permitopen` in `authorized_keys` matches the DB host:port |

## On-Premise Installation

For single-tenant on-premise deployments (no Caddy, direct port access):

```bash
git clone https://github.com/niolson/polybag.git
cd polybag
./scripts/install-onprem.sh
```

The installer will:
1. Prompt for domain/IP
2. Generate database and Redis passwords
3. Build and start containers in standalone mode
4. Generate app key and QZ Tray certificate
5. Print instructions for creating the first admin user

On-prem uses `docker-compose.onprem.yml` to publish nginx ports directly (no reverse proxy needed).
