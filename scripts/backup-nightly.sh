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
# Config (read from the environment / shared secrets):
#   SENTRY_LARAVEL_DSN or SENTRY_DSN  - the project DSN (also in /opt/shared/shared-secrets.env)
#   SENTRY_MONITOR_SLUG               - cron monitor slug (default: polybagapp)
#   S3_* / BACKUP_*                   - from /opt/shared/backup.env (used by the keyring upload)

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

sentry_checkin "$STATUS"
log "Nightly backup finished with status: ${STATUS}"

[ "$STATUS" = "ok" ]
