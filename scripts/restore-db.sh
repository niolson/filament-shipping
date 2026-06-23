#!/bin/bash
#
# Restore a PolyBag database backup from S3 (or a local file).
#
# The encryption key is selected automatically from the backup's ".kN" version
# tag via the versioned keyring, so backups stay restorable across key rotations.
#
# Usage:
#   ./scripts/restore-db.sh --list
#   ./scripts/restore-db.sh <object-name> --into <database> [--yes]
#   ./scripts/restore-db.sh --from-file <path> --into <database> [--yes]
#
# Examples:
#   ./scripts/restore-db.sh polybag_demo_2026-06-22_080000.k2.sql.gz.enc --into polybag_demo_restore
#
# Restoring OVERWRITES tables in the target database. By default a different
# (scratch) database is expected; pass --yes to skip the confirmation prompt.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib/backup-keys.sh
source "${SCRIPT_DIR}/lib/backup-keys.sh"

BACKUP_ENV="/opt/shared/backup.env"
if [ ! -f "$BACKUP_ENV" ]; then
    echo "ERROR: $BACKUP_ENV not found."
    exit 1
fi
# shellcheck source=/dev/null
source "$BACKUP_ENV"

S3_BUCKET="${S3_BUCKET:-polybag}"
S3_ENDPOINT="${S3_ENDPOINT:-https://hel1.your-objectstorage.com}"
S3_PREFIX="${S3_PREFIX:-backups/db}"
MYSQL_CONTAINER="${MYSQL_CONTAINER:-shared-mysql}"
export AWS_ACCESS_KEY_ID="${S3_ACCESS_KEY:-}"
export AWS_SECRET_ACCESS_KEY="${S3_SECRET_KEY:-}"

# --- Parse arguments ---

OBJECT=""
FROM_FILE=""
INTO=""
YES=false
LIST=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --list)      LIST=true; shift ;;
        --into)      INTO="$2"; shift 2 ;;
        --from-file) FROM_FILE="$2"; shift 2 ;;
        --yes)       YES=true; shift ;;
        -*)          echo "Unknown option: $1"; exit 1 ;;
        *)           OBJECT="$1"; shift ;;
    esac
done

if $LIST; then
    aws s3 ls "s3://${S3_BUCKET}/${S3_PREFIX}/" --endpoint-url "$S3_ENDPOINT" | sort
    exit 0
fi

if [ -z "$INTO" ]; then
    echo "ERROR: --into <database> is required."
    exit 1
fi

TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR"' EXIT

# --- Obtain the backup file ---

if [ -n "$FROM_FILE" ]; then
    [ -f "$FROM_FILE" ] || { echo "ERROR: $FROM_FILE not found."; exit 1; }
    NAME=$(basename "$FROM_FILE")
    SRC="$FROM_FILE"
else
    [ -n "$OBJECT" ] || { echo "ERROR: provide a backup object name or --from-file."; exit 1; }
    NAME=$(basename "$OBJECT")
    SRC="${TMPDIR}/${NAME}"
    echo "Downloading ${NAME} from S3..."
    aws s3 cp "s3://${S3_BUCKET}/${S3_PREFIX}/${NAME}" "$SRC" --endpoint-url "$S3_ENDPOINT" --quiet
fi

# --- Resolve the decryption key from the backup's version tag ---

backup_load_keyring
DECRYPT_KEY=""
if [[ "$NAME" == *.enc ]]; then
    if ! DECRYPT_KEY=$(backup_key_for_name "$NAME"); then
        VER=$(backup_version_from_name "$NAME")
        echo "ERROR: no key available to decrypt ${NAME} (needs key version '${VER:-legacy}'). Add it to ${BACKUP_KEYRING}."
        exit 1
    fi
fi

# --- Resolve MySQL root password (same strategy as backup-db.sh) ---

MYSQL_ROOT_PASS=$(grep '^MYSQL_ROOT_PASSWORD=' /opt/shared/.env 2>/dev/null | cut -d= -f2- || true)
if [ -z "$MYSQL_ROOT_PASS" ]; then
    MYSQL_ROOT_PASS=$(docker exec "$MYSQL_CONTAINER" printenv MYSQL_ROOT_PASSWORD 2>/dev/null || true)
fi
if [ -z "$MYSQL_ROOT_PASS" ] || ! docker exec "$MYSQL_CONTAINER" mysql -uroot -p"$MYSQL_ROOT_PASS" -e "SELECT 1" >/dev/null 2>&1; then
    echo "ERROR: could not authenticate to ${MYSQL_CONTAINER} as root."
    exit 1
fi

# --- Confirm ---

echo "About to restore '${NAME}' INTO database '${INTO}' on ${MYSQL_CONTAINER}."
echo "This OVERWRITES matching tables in '${INTO}'."
if ! $YES; then
    read -r -p "Continue? [y/N] " REPLY
    [[ "$REPLY" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 1; }
fi

# --- Decrypt / decompress into a plain SQL stream, then load ---

SQL="${TMPDIR}/restore.sql"
if [[ "$NAME" == *.enc ]]; then
    export BACKUP_ENC_PASS="$DECRYPT_KEY"
    openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_ENC_PASS -in "$SRC" | gunzip >"$SQL"
elif [[ "$NAME" == *.gz ]]; then
    gunzip -c "$SRC" >"$SQL"
else
    cp "$SRC" "$SQL"
fi

if [ ! -s "$SQL" ]; then
    echo "ERROR: decrypted/decompressed dump is empty — wrong key or corrupt file."
    exit 1
fi

docker exec "$MYSQL_CONTAINER" mysql -uroot -p"$MYSQL_ROOT_PASS" \
    -e "CREATE DATABASE IF NOT EXISTS \`${INTO}\`"
docker exec -i "$MYSQL_CONTAINER" mysql -uroot -p"$MYSQL_ROOT_PASS" "$INTO" <"$SQL"

echo "Restore complete: ${NAME} -> ${INTO}"
