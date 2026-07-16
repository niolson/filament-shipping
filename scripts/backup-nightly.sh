#!/bin/bash
#
# Nightly backup wrapper for PolyBag.
#
# Runs the database backup (scripts/backup-db.sh) and the MySQL keyring backup
# as a single unit, bracketed by Sentry Crons check-ins so that a missed or
# failed nightly backup raises an alert. This is intended to be the only backup
# entry in the root crontab:
#
#   0 8 * * * /opt/tenants/<tenant>/scripts/backup-nightly.sh >> /var/log/polybag-backup.log 2>&1
#
# Sentry check-ins are sent only when a DSN is found, so the script is a no-op
# on the monitoring side in environments without Sentry configured.
#
# Also checks that the object storage credential (backup.env) and the backup
# encryption keyring haven't gone past their rotation policy — nothing else in
# the codebase tracks credential age, so this piggybacks on the one thing that
# already runs daily and pages on failure. A stale credential fails the same
# Sentry monitor a broken backup would.
#
# Config (read from the environment / shared secrets):
#   SENTRY_LARAVEL_DSN or SENTRY_DSN  - the project DSN (also in /opt/shared/shared-secrets.env)
#   SENTRY_MONITOR_SLUG               - cron monitor slug (default: polybagapp)
#   S3_* / BACKUP_*                   - from /opt/shared/backup.env (used by the keyring upload)
#   MAX_KEY_AGE_DAYS                  - rotation policy in days (default: 370, i.e. annual + slack)

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# S3 credentials for the keyring upload (backup-db.sh sources this itself too).
if [ -f /opt/shared/backup.env ]; then
    # shellcheck disable=SC1091
    source /opt/shared/backup.env
fi

# Resolve the Sentry DSN: prefer the environment, fall back to shared secrets.
SENTRY_DSN_VALUE="${SENTRY_LARAVEL_DSN:-${SENTRY_DSN:-}}"
if [ -z "$SENTRY_DSN_VALUE" ]; then
    SENTRY_DSN_VALUE=$(grep -hE '^SENTRY_(LARAVEL_)?DSN=' /opt/shared/shared-secrets.env 2>/dev/null | head -1 | cut -d= -f2-)
fi
MONITOR_SLUG="${SENTRY_MONITOR_SLUG:-polybagapp}"

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') [nightly] $*"; }

# --- Key rotation age check ---
#
# Neither rotate-internal-secrets.sh nor rotate-storage-key.sh record when they
# were last run, so this uses each credential file's mtime as a proxy. A file
# edited (even partially) by a rotation script gets a fresh mtime, so this is
# reset by the normal rotation scripts without any extra bookkeeping.

MAX_KEY_AGE_DAYS="${MAX_KEY_AGE_DAYS:-370}"
BACKUP_KEYRING="${BACKUP_KEYRING:-/opt/shared/backup-keys.env}"

# Warns (and signals staleness via return code) if $1 is older than the policy.
# Missing files are skipped rather than flagged — not every server has a
# versioned keyring (legacy single-key mode keeps the key in backup.env itself).
check_key_age() {
    local file="$1" label="$2" mtime now age_days
    [ -f "$file" ] || return 0

    mtime=$(stat -c %Y "$file" 2>/dev/null) || { log "WARN: could not stat ${file} for age check"; return 0; }
    now=$(date +%s)
    age_days=$(( (now - mtime) / 86400 ))

    if [ "$age_days" -gt "$MAX_KEY_AGE_DAYS" ]; then
        log "WARN: ${label} (${file}) is ${age_days} days old — exceeds the ${MAX_KEY_AGE_DAYS}-day rotation policy. Rotate it (see docs/server-setup.md)."
        return 1
    fi
    log "${label} age OK (${age_days} days)."
    return 0
}

# Send a Sentry Crons check-in. Status is one of: in_progress | ok | error.
# Sentry associates the terminal check-in with the most recent open one for the
# monitor, so no client-side check-in id bookkeeping is needed.
sentry_checkin() {
    local status="$1"
    [ -n "$SENTRY_DSN_VALUE" ] || return 0

    local rest key hostpath host project
    rest="${SENTRY_DSN_VALUE#*://}"   # <key>@<host>/<project>
    key="${rest%%@*}"
    hostpath="${rest#*@}"             # <host>/<project>
    host="${hostpath%%/*}"
    project="${hostpath##*/}"

    if [ -z "$key" ] || [ -z "$host" ] || [ -z "$project" ]; then
        log "WARN: could not parse Sentry DSN; skipping check-in"
        return 0
    fi

    curl -sf -m 10 \
        "https://${host}/api/${project}/cron/${MONITOR_SLUG}/${key}/?status=${status}" \
        >/dev/null 2>&1 \
        || log "WARN: Sentry check-in (${status}) failed to send"
}

# --- Run ---

sentry_checkin in_progress
STATUS="ok"

log "Starting database backup..."
if ! "${SCRIPT_DIR}/backup-db.sh"; then
    log "ERROR: database backup failed"
    STATUS="error"
fi

log "Starting keyring backup..."
if docker cp shared-mysql:/var/lib/mysql-keyring/component_keyring_file /tmp/keyring \
    && AWS_ACCESS_KEY_ID="${S3_ACCESS_KEY:-}" AWS_SECRET_ACCESS_KEY="${S3_SECRET_KEY:-}" \
        aws s3 cp /tmp/keyring "s3://${S3_BUCKET:-polybag}/backups/keyring/keyring-$(date +%Y-%m-%d)" \
        --endpoint-url "${S3_ENDPOINT:-https://hel1.your-objectstorage.com}" --quiet; then
    log "Keyring backup uploaded."
else
    log "ERROR: keyring backup failed"
    STATUS="error"
fi
rm -f /tmp/keyring

log "Checking credential rotation age..."
check_key_age "/opt/shared/backup.env" "Object storage credential (S3_ACCESS_KEY/S3_SECRET_KEY)" || STATUS="error"
check_key_age "$BACKUP_KEYRING" "Backup encryption keyring" || STATUS="error"

sentry_checkin "$STATUS"
log "Nightly backup finished with status: ${STATUS}"

[ "$STATUS" = "ok" ]
