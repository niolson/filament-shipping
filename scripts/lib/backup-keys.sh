#!/bin/bash
#
# Shared helpers for versioned backup encryption keys.
#
# The keyring file (default /opt/shared/backup-keys.env, override with
# BACKUP_KEYRING) holds one current-version pointer and one key per version:
#
#   BACKUP_KEY_CURRENT=2
#   BACKUP_KEY_1=<hex>
#   BACKUP_KEY_2=<hex>
#
# Backups are encrypted with BACKUP_KEY_${CURRENT} and their object names carry
# a ".k<n>." tag (e.g. polybag_demo_2026-06-22_080000.k2.sql.gz.enc) so every
# backup declares which key decrypts it — the bucket itself is the ledger.
#
# Backups created before versioning carry no tag; they are decrypted with the
# legacy single key, which the keyring stores as BACKUP_KEY_1 after migration.
#
# These functions are pure (no side effects beyond reading the keyring) so they
# can be unit-tested without MySQL, S3, or Docker. Source this file, then call
# backup_load_keyring once.

BACKUP_KEYRING="${BACKUP_KEYRING:-/opt/shared/backup-keys.env}"

# Load the keyring into the environment. No-op (success) if it does not exist,
# so legacy single-key deployments keep working.
backup_load_keyring() {
    if [ -f "$BACKUP_KEYRING" ]; then
        # shellcheck disable=SC1090
        source "$BACKUP_KEYRING"
    fi
    return 0
}

# Echo the current key version, or nothing if the keyring is absent (legacy).
backup_current_version() {
    echo "${BACKUP_KEY_CURRENT:-}"
}

# Echo the key for a version number. Returns non-zero if that version is absent.
backup_key_for_version() {
    local var="BACKUP_KEY_${1}"
    local key="${!var:-}"
    [ -n "$key" ] || return 1
    echo "$key"
}

# Echo the version tag parsed from a backup object name, or nothing if untagged.
backup_version_from_name() {
    if [[ "$1" =~ \.k([0-9]+)\. ]]; then
        echo "${BASH_REMATCH[1]}"
    fi
}

# Echo the decryption key required for a given backup object name. Tagged names
# resolve to their version's key; untagged (legacy) names use BACKUP_KEY_1 if the
# keyring has been migrated, otherwise the legacy single BACKUP_ENCRYPTION_KEY.
# Returns non-zero (and echoes nothing) if the required key is not available.
backup_key_for_name() {
    local version
    version=$(backup_version_from_name "$1")

    if [ -n "$version" ]; then
        backup_key_for_version "$version"
        return $?
    fi

    if [ -n "${BACKUP_KEY_1:-}" ]; then
        echo "$BACKUP_KEY_1"
        return 0
    fi
    if [ -n "${BACKUP_ENCRYPTION_KEY:-}" ]; then
        echo "$BACKUP_ENCRYPTION_KEY"
        return 0
    fi
    return 1
}
