#!/bin/bash
#
# Small helpers for comma-separated env list values (e.g. APP_PREVIOUS_KEYS).
# Pure functions, no side effects — unit-tested in env-list.test.sh.

# Prepend $1 to the comma-separated list $2, dropping any duplicate of $1.
# Echoes the resulting comma-separated list.
#
# Used so APP_KEY rotation never discards a key that still decrypts data: the
# outgoing key is prepended to the existing previous-key list rather than
# overwriting it, so a retry after a partial failure can't lose the original key.
prepend_unique() {
    local head="$1" rest="${2:-}" out="$1" item
    local _items=()

    if [ -n "$rest" ]; then
        IFS=',' read -ra _items <<< "$rest"
        for item in "${_items[@]}"; do
            if [ -n "$item" ] && [ "$item" != "$head" ]; then
                out="${out},${item}"
            fi
        done
    fi

    echo "$out"
}
