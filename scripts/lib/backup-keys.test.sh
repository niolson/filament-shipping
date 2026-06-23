#!/bin/bash
#
# Unit tests for scripts/lib/backup-keys.sh — the version parsing / key selection
# logic, where a bug means unrestorable backups. Run: bash scripts/lib/backup-keys.test.sh
#
# Self-contained: uses a temp keyring and openssl, no MySQL/S3/Docker.

set -uo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

FAILS=0
assert_eq() {
    local got="$1" want="$2" msg="$3"
    if [ "$got" = "$want" ]; then
        echo "  ok: $msg"
    else
        echo "  FAIL: $msg — got '$got', want '$want'"
        FAILS=$((FAILS + 1))
    fi
}
assert_fails() {
    if "$@" >/dev/null 2>&1; then
        echo "  FAIL: expected non-zero exit from: $*"
        FAILS=$((FAILS + 1))
    else
        echo "  ok: correctly errored: $*"
    fi
}

# Build a keyring with two versions, current = 2.
KEY1=$(openssl rand -hex 32)
KEY2=$(openssl rand -hex 32)
cat >"$TMP/backup-keys.env" <<EOF
BACKUP_KEY_CURRENT=2
BACKUP_KEY_1=$KEY1
BACKUP_KEY_2=$KEY2
EOF

export BACKUP_KEYRING="$TMP/backup-keys.env"
# shellcheck source=/dev/null
source "$DIR/backup-keys.sh"
backup_load_keyring

echo "== version parsing =="
assert_eq "$(backup_version_from_name 'polybag_demo_2026-06-22_080000.k2.sql.gz.enc')" "2" "parses .k2. tag"
assert_eq "$(backup_version_from_name 'polybag_demo_2026-06-22_080000.k13.sql.gz.enc')" "13" "parses multi-digit tag"
assert_eq "$(backup_version_from_name 'polybag_demo_2026-06-22_080000.sql.gz.enc')" "" "untagged name has no version"

echo "== key selection =="
assert_eq "$(backup_current_version)" "2" "current version is 2"
assert_eq "$(backup_key_for_version 1)" "$KEY1" "version 1 -> KEY1"
assert_eq "$(backup_key_for_version 2)" "$KEY2" "version 2 -> KEY2"
assert_fails backup_key_for_version 9
assert_eq "$(backup_key_for_name 'db_2026-06-22_080000.k1.sql.gz.enc')" "$KEY1" "tagged k1 name -> KEY1"
assert_eq "$(backup_key_for_name 'db_2026-06-22_080000.k2.sql.gz.enc')" "$KEY2" "tagged k2 name -> KEY2"
assert_eq "$(backup_key_for_name 'db_2026-06-22_080000.sql.gz.enc')" "$KEY1" "untagged name -> KEY1 (migrated legacy)"
assert_fails backup_key_for_name 'db_2026-06-22_080000.k9.sql.gz.enc'

echo "== encrypt/decrypt round-trip (current key) =="
PLAIN="$TMP/plain.sql"
echo "CREATE TABLE t (id INT); -- secret payload $(openssl rand -hex 8)" >"$PLAIN"

VER=$(backup_current_version)
CURKEY=$(backup_key_for_version "$VER")
ENC="$TMP/db_2026-06-22_080000.k${VER}.sql.gz.enc"
gzip -c "$PLAIN" | openssl enc -aes-256-cbc -pbkdf2 -pass "pass:$CURKEY" -out "$ENC"

# Restore picks the key purely from the filename tag.
DECKEY=$(backup_key_for_name "$(basename "$ENC")")
DEC="$TMP/decrypted.sql"
openssl enc -d -aes-256-cbc -pbkdf2 -pass "pass:$DECKEY" -in "$ENC" | gunzip >"$DEC"
assert_eq "$(cat "$DEC")" "$(cat "$PLAIN")" "current-key (k${VER}) round-trip restores original"

echo "== old-key object stays restorable after rotation =="
# An object encrypted with k1 must still restore while current is k2.
OLDENC="$TMP/db_2026-01-01_080000.k1.sql.gz.enc"
gzip -c "$PLAIN" | openssl enc -aes-256-cbc -pbkdf2 -pass "pass:$KEY1" -out "$OLDENC"
OLDKEY=$(backup_key_for_name "$(basename "$OLDENC")")
openssl enc -d -aes-256-cbc -pbkdf2 -pass "pass:$OLDKEY" -in "$OLDENC" | gunzip >"$TMP/old-dec.sql"
assert_eq "$(cat "$TMP/old-dec.sql")" "$(cat "$PLAIN")" "k1 object restores with k1 while current is k2"

echo
if [ "$FAILS" -eq 0 ]; then
    echo "All backup-keys tests passed."
    exit 0
fi
echo "${FAILS} assertion(s) failed."
exit 1
