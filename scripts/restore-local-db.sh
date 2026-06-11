#!/bin/bash
#
# Restore the local PolyBag development database from a backup created by
# scripts/backup-local-db.sh. Connection details are read from .env.
#
# Usage:
#   ./scripts/restore-local-db.sh storage/app/private/db-backups/<file>.sql.gz

set -euo pipefail

cd "$(dirname "$0")/.."

if [ -z "${1:-}" ]; then
    echo "Usage: $0 <backup-file.sql.gz>"
    exit 1
fi

BACKUP_FILE="$1"
if [ ! -f "$BACKUP_FILE" ]; then
    echo "ERROR: $BACKUP_FILE not found"
    exit 1
fi

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

echo "This will overwrite database '${DB_DATABASE}' on ${DB_HOST} with ${BACKUP_FILE}."
read -p "Continue? [y/N] " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Aborted."
    exit 1
fi

gunzip -c "$BACKUP_FILE" | mysql -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"

echo "Restore complete."
