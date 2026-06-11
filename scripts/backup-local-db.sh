#!/bin/bash
#
# Daily backup of the local PolyBag development database.
#
# Dumps DB_DATABASE (from .env) to a timestamped, gzip-compressed file under
# storage/app/private/db-backups/, then prunes backups older than
# BACKUP_RETENTION_DAYS (default 14).
#
# Setup (cron, runs daily at 3am):
#   crontab -e
#   0 3 * * * /home/nick/sites/polybag/scripts/backup-local-db.sh >> /home/nick/sites/polybag/storage/logs/db-backup.log 2>&1
#
# Restore:
#   ./scripts/restore-local-db.sh storage/app/private/db-backups/<file>.sql.gz

set -euo pipefail

cd "$(dirname "$0")/.."

ENV_FILE=".env"
if [ ! -f "$ENV_FILE" ]; then
    echo "ERROR: .env not found"
    exit 1
fi

DB_HOST=$(grep '^DB_HOST=' "$ENV_FILE" | cut -d= -f2-)
DB_PORT=$(grep '^DB_PORT=' "$ENV_FILE" | cut -d= -f2-)
DB_DATABASE=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)
DB_USERNAME=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2-)
DB_PASSWORD=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)

BACKUP_DIR="storage/app/private/db-backups"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
DATE=$(date +%Y-%m-%d_%H%M%S)
FILENAME="${DB_DATABASE}_${DATE}.sql.gz"

mkdir -p "$BACKUP_DIR"

mysqldump -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" -p"$DB_PASSWORD" \
    --single-transaction --routines --triggers "$DB_DATABASE" | gzip > "${BACKUP_DIR}/${FILENAME}"

SIZE=$(du -h "${BACKUP_DIR}/${FILENAME}" | cut -f1)
echo "$(date '+%Y-%m-%d %H:%M:%S') Backed up ${DB_DATABASE} to ${BACKUP_DIR}/${FILENAME} (${SIZE})"

find "$BACKUP_DIR" -name "${DB_DATABASE}_*.sql.gz" -mtime "+${RETENTION_DAYS}" -print -delete
