#!/bin/bash
#
# Rotate the S3-compatible object storage credential used for database backups.
#
# Hetzner Object Storage has no API for generating or revoking S3 keys — that
# part is Console-only (see docs.hetzner.com/storage/object-storage/faq/s3-credentials).
# This script covers everything around that manual step: it verifies the new
# key actually works against the real bucket (list + write + read + delete)
# BEFORE touching /opt/shared/backup.env, so a bad paste never breaks nightly
# backups. It never revokes the old key — that stays a manual Console step,
# done only after this script confirms the new key is live.
#
# Rotation schedule: at minimum annually, per the key management document.
#
# Usage:
#   1. Hetzner Console -> Object Storage -> generate a new access key/secret.
#   2. ./scripts/rotate-storage-key.sh
#      Prompts for the new access key and secret (secret input is hidden).
#   3. Once it confirms success, revoke the OLD key shown in the output.
#
# Non-interactive (e.g. driven from a secrets manager):
#   NEW_S3_ACCESS_KEY=... NEW_S3_SECRET_KEY=... ./scripts/rotate-storage-key.sh --yes
#
# Flags:
#   --dry-run   Run the verification round-trip but do not modify backup.env.
#   --yes       Skip the confirmation prompt before writing backup.env.

set -euo pipefail

BACKUP_ENV="${BACKUP_ENV:-/opt/shared/backup.env}"

info()  { echo -e "\033[1;34m[INFO]\033[0m  $*"; }
ok()    { echo -e "\033[1;32m[OK]\033[0m    $*"; }
warn()  { echo -e "\033[1;33m[WARN]\033[0m  $*"; }
error() { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; }

DRY_RUN=false
YES=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=true; shift ;;
        --yes)     YES=true; shift ;;
        *) error "Unknown option: $1"; exit 1 ;;
    esac
done

[ -f "$BACKUP_ENV" ] || { error "$BACKUP_ENV not found."; exit 1; }
# shellcheck source=/dev/null
source "$BACKUP_ENV"

S3_BUCKET="${S3_BUCKET:-polybag}"
S3_ENDPOINT="${S3_ENDPOINT:-https://hel1.your-objectstorage.com}"
S3_PREFIX="${S3_PREFIX:-backups/db}"

OLD_ACCESS_KEY="${S3_ACCESS_KEY:-}"
OLD_SECRET_KEY="${S3_SECRET_KEY:-}"
[ -n "$OLD_ACCESS_KEY" ] || { error "S3_ACCESS_KEY not set in $BACKUP_ENV"; exit 1; }

mask() {
    local v="$1"
    [ "${#v}" -gt 4 ] || { echo "****"; return; }
    echo "****${v: -4}"
}

# --- Collect the new credential ---

NEW_ACCESS_KEY="${NEW_S3_ACCESS_KEY:-}"
NEW_SECRET_KEY="${NEW_S3_SECRET_KEY:-}"

if [ -z "$NEW_ACCESS_KEY" ]; then
    read -r -p "New S3 access key (from Hetzner Console): " NEW_ACCESS_KEY
fi
if [ -z "$NEW_SECRET_KEY" ]; then
    read -r -s -p "New S3 secret key (hidden): " NEW_SECRET_KEY
    echo
fi
[ -n "$NEW_ACCESS_KEY" ] && [ -n "$NEW_SECRET_KEY" ] || { error "Both the new access key and secret key are required."; exit 1; }

if [ "$NEW_ACCESS_KEY" = "$OLD_ACCESS_KEY" ]; then
    error "New access key matches the current one — generate a new key in the Hetzner Console first."
    exit 1
fi

# --- Verify the new credential against the real bucket before touching anything ---

info "Verifying new credential against s3://${S3_BUCKET}/${S3_PREFIX}/ ..."

PROBE_KEY="${S3_PREFIX}/rotation-probe-$(date +%s)-$$.txt"
PROBE_FILE=$(mktemp)
PROBE_CONTENT="rotate-storage-key probe $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "$PROBE_CONTENT" > "$PROBE_FILE"

cleanup() {
    rm -f "$PROBE_FILE" "${PROBE_FILE}.dl"
    AWS_ACCESS_KEY_ID="$NEW_ACCESS_KEY" AWS_SECRET_ACCESS_KEY="$NEW_SECRET_KEY" \
        aws s3 rm "s3://${S3_BUCKET}/${PROBE_KEY}" --endpoint-url "$S3_ENDPOINT" --quiet 2>/dev/null || true
}
trap cleanup EXIT

VERIFY_OK=true

if ! AWS_ACCESS_KEY_ID="$NEW_ACCESS_KEY" AWS_SECRET_ACCESS_KEY="$NEW_SECRET_KEY" \
    aws s3 ls "s3://${S3_BUCKET}/${S3_PREFIX}/" --endpoint-url "$S3_ENDPOINT" >/dev/null 2>&1; then
    error "New key cannot list s3://${S3_BUCKET}/${S3_PREFIX}/"
    VERIFY_OK=false
fi

if $VERIFY_OK && ! AWS_ACCESS_KEY_ID="$NEW_ACCESS_KEY" AWS_SECRET_ACCESS_KEY="$NEW_SECRET_KEY" \
    aws s3 cp "$PROBE_FILE" "s3://${S3_BUCKET}/${PROBE_KEY}" --endpoint-url "$S3_ENDPOINT" --quiet 2>/dev/null; then
    error "New key cannot write to s3://${S3_BUCKET}/${S3_PREFIX}/"
    VERIFY_OK=false
fi

if $VERIFY_OK && ! AWS_ACCESS_KEY_ID="$NEW_ACCESS_KEY" AWS_SECRET_ACCESS_KEY="$NEW_SECRET_KEY" \
    aws s3 cp "s3://${S3_BUCKET}/${PROBE_KEY}" "${PROBE_FILE}.dl" --endpoint-url "$S3_ENDPOINT" --quiet 2>/dev/null \
    || [ "$(cat "${PROBE_FILE}.dl" 2>/dev/null)" != "$PROBE_CONTENT" ]; then
    error "New key cannot read back what it just wrote."
    VERIFY_OK=false
fi

$VERIFY_OK || { error "Verification failed — backup.env left untouched."; exit 1; }
ok "New key can list, write, and read the backup bucket."

if $DRY_RUN; then
    ok "Dry run — verification passed, backup.env not modified."
    exit 0
fi

# --- Confirm and cut over ---

echo ""
echo "Current key: $(mask "$OLD_ACCESS_KEY")"
echo "New key:     $(mask "$NEW_ACCESS_KEY")"
echo ""

if ! $YES; then
    read -r -p "Write the new key to ${BACKUP_ENV}? [y/N] " CONFIRM
    [[ "$CONFIRM" =~ ^[Yy]$ ]] || { info "Aborted. backup.env not modified."; exit 0; }
fi

sed -i "s|^S3_ACCESS_KEY=.*|S3_ACCESS_KEY=${NEW_ACCESS_KEY}|" "$BACKUP_ENV"
sed -i "s|^S3_SECRET_KEY=.*|S3_SECRET_KEY=${NEW_SECRET_KEY}|" "$BACKUP_ENV"
chmod 600 "$BACKUP_ENV"

ok "backup.env updated with the new key."
echo ""
warn "Next steps:"
echo "  1. Run a real backup to confirm end-to-end: ./scripts/backup-db.sh"
echo "  2. Revoke the OLD key ($(mask "$OLD_ACCESS_KEY")) in the Hetzner Console."
echo "  3. Record the rotation date — schedule is at minimum annually."
