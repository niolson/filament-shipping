#!/bin/bash
#
# Unit tests for scripts/lib/env-list.sh. Run: bash scripts/lib/env-list.test.sh

set -uo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=env-list.sh
source "$DIR/env-list.sh"

FAILS=0
assert_eq() {
    if [ "$1" = "$2" ]; then
        echo "  ok: $3"
    else
        echo "  FAIL: $3 — got '$1', want '$2'"
        FAILS=$((FAILS + 1))
    fi
}

echo "== prepend_unique =="
assert_eq "$(prepend_unique K1 '')"        "K1"        "empty existing list"
assert_eq "$(prepend_unique K1 'K0')"      "K1,K0"     "prepends to single existing"
assert_eq "$(prepend_unique K2 'K1,K0')"   "K2,K1,K0"  "prepends to multi list"
assert_eq "$(prepend_unique K1 'K1,K0')"   "K1,K0"     "dedupes head already at front"
assert_eq "$(prepend_unique K1 'K0,K1')"   "K1,K0"     "dedupes head from elsewhere"
# Real APP_KEY-shaped values (base64 with / + =).
A='base64:aa/bb+cc=='
B='base64:dd/ee+ff=='
assert_eq "$(prepend_unique "$B" "$A")"    "$B,$A"     "handles base64 key values"
assert_eq "$(prepend_unique "$A" "$A")"    "$A"        "dedupes identical base64 key"

echo "== first_of =="
assert_eq "$(first_of '')"          ""      "empty list"
assert_eq "$(first_of 'K1')"        "K1"    "single element"
assert_eq "$(first_of 'K2,K1,K0')"  "K2"    "first of many"
assert_eq "$(first_of "$B,$A")"     "$B"    "first base64 element"

echo
if [ "$FAILS" -eq 0 ]; then
    echo "All env-list tests passed."
    exit 0
fi
echo "${FAILS} assertion(s) failed."
exit 1
