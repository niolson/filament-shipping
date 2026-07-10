#!/usr/bin/env bash
#
# Reconnect the shared datastores to every per-tenant network.
#
# Each tenant (shared mode) runs on its own `shared-<tenant>` network for
# isolation, and the shared datastores (MySQL/Redis/Gotenberg) are attached to
# all of them (see issue 16). Recreating a datastore container — e.g. an image
# update in /opt/shared — brings it back only on its compose network(s) and drops
# those per-tenant attachments, cutting every tenant off from it.
#
# Run this after any such recreation to restore the attachments. It is idempotent
# (re-connecting an already-connected network is a no-op), so it is also safe to
# run from cron as a safety net.
#
# Usage: ./scripts/reconnect-shared-networks.sh

set -euo pipefail

SHARED_SERVICES=(shared-mysql shared-redis gotenberg)

mapfile -t TENANT_NETWORKS < <(docker network ls --format '{{.Name}}' | grep -E '^shared-')

if [ "${#TENANT_NETWORKS[@]}" -eq 0 ]; then
    echo "No per-tenant (shared-*) networks found — nothing to do."
    exit 0
fi

for net in "${TENANT_NETWORKS[@]}"; do
    for svc in "${SHARED_SERVICES[@]}"; do
        if docker inspect "$svc" &>/dev/null; then
            if docker network connect "$net" "$svc" 2>/dev/null; then
                echo "connected ${svc} -> ${net}"
            fi
        fi
    done
done

echo "Done."
