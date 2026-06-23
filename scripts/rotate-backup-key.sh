#!/bin/bash
#
# Rotate or retire the backup encryption keyring.
#
# Rotation is additive and safe: it generates a new key, makes it current, and
# NEVER deletes an old key — existing backups encrypted with prior keys stay
# restorable (each backup carries a ".kN" version tag). Retiring an old key is a
# separate, guarded step that only removes a key once no backup in S3 still needs
# it (let the retention window age those backups out first).
#
# Usage:
#   ./scripts/rotate-backup-key.sh             # add a new key version, make it current
#   ./scripts/rotate-backup-key.sh --retire    # remove keys no backup still requires
#   ./scripts/rotate-backup-key.sh --dry-run   # preview (combine with --retire)
#
# The first rotation migrates the legacy single BACKUP_ENCRYPTION_KEY into the
# keyring as version 1, then adds version 2 as the new current key.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib/backup-keys.sh
source "${SCRIPT_DIR}/lib/backup-keys.sh"

BACKUP_ENV="${BACKUP_ENV:-/opt/shared/backup.env}"
if [ -f "$BACKUP_ENV" ]; then
    # shellcheck source=/dev/null
    source "$BACKUP_ENV"
fi

S3_BUCKET="${S3_BUCKET:-polybag}"
S3_ENDPOINT="${S3_ENDPOINT:-https://hel1.your-objectstorage.com}"
S3_PREFIX="${S3_PREFIX:-backups/db}"

info()  { echo -e "\033[1;34m[INFO]\033[0m  $*"; }
ok()    { echo -e "\033[1;32m[OK]\033[0m    $*"; }
warn()  { echo -e "\033[1;33m[WARN]\033[0m  $*"; }
error() { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; }

RETIRE=false
DRY_RUN=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --retire)  RETIRE=true; shift ;;
        --dry-run) DRY_RUN=true; shift ;;
        *) error "Unknown option: $1"; exit 1 ;;
    esac
done

# Upsert / delete a KEY=value line in the keyring file.
keyring_set() {
    local key="$1" value="$2"
    if grep -qE "^${key}=" "$BACKUP_KEYRING" 2>/dev/null; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$BACKUP_KEYRING"
    else
        printf '%s=%s\n' "$key" "$value" >>"$BACKUP_KEYRING"
    fi
}
keyring_unset() {
    sed -i "/^${1}=/d" "$BACKUP_KEYRING"
}

# Verify a key can encrypt and decrypt a round-trip before trusting it.
self_test_key() {
    local key="$1" probe dec
    probe="rotate-probe-$(openssl rand -hex 4)"
    dec=$(printf '%s' "$probe" \
        | openssl enc -aes-256-cbc -pbkdf2 -pass "pass:${key}" \
        | openssl enc -d -aes-256-cbc -pbkdf2 -pass "pass:${key}" 2>/dev/null)
    [ "$dec" = "$probe" ]
}

# --- Retire mode ---

if $RETIRE; then
    [ -f "$BACKUP_KEYRING" ] || { error "Keyring not found: $BACKUP_KEYRING"; exit 1; }
    backup_load_keyring
    CURRENT=$(backup_current_version)

    export AWS_ACCESS_KEY_ID="${S3_ACCESS_KEY:-}"
    export AWS_SECRET_ACCESS_KEY="${S3_SECRET_KEY:-}"
    OBJECTS=$(aws s3 ls "s3://${S3_BUCKET}/${S3_PREFIX}/" --endpoint-url "$S3_ENDPOINT" 2>/dev/null | awk '{print $NF}')

    # Legacy (untagged) encrypted objects are decrypted with key version 1.
    UNTAGGED=$(echo "$OBJECTS" | grep -E '\.sql\.gz\.enc$' | grep -vE '\.k[0-9]+\.' || true)

    retired=0
    for var in $(compgen -v | grep -E '^BACKUP_KEY_[0-9]+$' | sort -t_ -k3 -n); do
        n="${var##*_}"
        [ "$n" = "$CURRENT" ] && continue

        in_use=false
        echo "$OBJECTS" | grep -q "\.k${n}\." && in_use=true
        if [ "$n" = "1" ] && [ -n "$UNTAGGED" ]; then
            in_use=true
        fi

        if $in_use; then
            count=$(echo "$OBJECTS" | grep -c "\.k${n}\." || true)
            [ "$n" = "1" ] && count=$((count + $(echo "$UNTAGGED" | grep -c . || true)))
            info "Keeping k${n} — still required by ${count} backup(s)."
        elif $DRY_RUN; then
            info "Would retire k${n} (no backups reference it)."
        else
            keyring_unset "$var"
            ok "Retired k${n}."
            retired=$((retired + 1))
        fi
    done

    if $DRY_RUN; then
        info "Dry run — no keys removed."
    else
        ok "Retire complete. ${retired} key(s) removed."
    fi
    exit 0
fi

# --- Rotate mode ---

if [ ! -f "$BACKUP_KEYRING" ]; then
    LEGACY="${BACKUP_ENCRYPTION_KEY:-}"
    if [ -z "$LEGACY" ]; then
        warn "No existing BACKUP_ENCRYPTION_KEY found — seeding k1 with a fresh key."
        LEGACY=$(openssl rand -hex 32)
    else
        info "Migrating existing backup key into the keyring as k1."
    fi
    if $DRY_RUN; then
        info "Would create ${BACKUP_KEYRING} with BACKUP_KEY_1 and BACKUP_KEY_CURRENT=1."
    else
        umask 077
        : >"$BACKUP_KEYRING"
        keyring_set "BACKUP_KEY_CURRENT" "1"
        keyring_set "BACKUP_KEY_1" "$LEGACY"
    fi
    backup_load_keyring
    BACKUP_KEY_CURRENT="${BACKUP_KEY_CURRENT:-1}"
fi

backup_load_keyring
CURRENT=$(backup_current_version)
[ -n "$CURRENT" ] || CURRENT=1
NEXT=$((CURRENT + 1))
NEWKEY=$(openssl rand -hex 32)

if ! self_test_key "$NEWKEY"; then
    error "New key failed an encrypt/decrypt self-test — aborting."
    exit 1
fi

if $DRY_RUN; then
    info "Would add BACKUP_KEY_${NEXT} and set BACKUP_KEY_CURRENT=${NEXT}."
    info "Dry run — no changes made."
    exit 0
fi

keyring_set "BACKUP_KEY_${NEXT}" "$NEWKEY"
keyring_set "BACKUP_KEY_CURRENT" "$NEXT"

echo ""
ok "Rotated backup encryption key to version ${NEXT}."
echo ""
warn "Next steps:"
echo "  1. Escrow the updated ${BACKUP_KEYRING} OFF this server (secrets manager / offline)."
echo "     It must NOT live only beside the backups in S3."
echo "  2. Run a backup to confirm the new key works end-to-end:"
echo "       ${SCRIPT_DIR}/backup-db.sh"
echo "  3. Keep older keys until their backups expire, then: $0 --retire"
