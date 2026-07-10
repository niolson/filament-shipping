#!/bin/bash
set -euo pipefail

# PolyBag Tenant Deployment Script
# Pulls the latest code and rebuilds containers for one or all tenants.
#
# Usage:
#   ./scripts/deploy-tenant.sh <tenant-name>   # Deploy a single tenant
#   ./scripts/deploy-tenant.sh --all           # Deploy all tenants in /opt/tenants
#
# Examples:
#   ./scripts/deploy-tenant.sh acme
#   ./scripts/deploy-tenant.sh --all

TENANTS_DIR="/opt/tenants"
HEALTH_TIMEOUT=120

# --- Helpers ---

info()    { echo -e "\033[1;34m[INFO]\033[0m  $*"; }
error()   { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; }
ok()      { echo -e "\033[1;32m[OK]\033[0m    $*"; }
warn()    { echo -e "\033[1;33m[WARN]\033[0m  $*"; }

# --- Parse arguments ---

if [ $# -lt 1 ]; then
    error "Usage: $0 <tenant-name> | --all"
    exit 1
fi

if [ "$1" = "--all" ]; then
    TENANTS=()
    for dir in "${TENANTS_DIR}"/*/; do
        name=$(basename "$dir")
        # Skip non-tenant directories (no docker-compose.yml)
        [ -f "${dir}docker-compose.yml" ] || continue
        TENANTS+=("$name")
    done

    if [ ${#TENANTS[@]} -eq 0 ]; then
        error "No tenants found in ${TENANTS_DIR}"
        exit 1
    fi

    info "Deploying ${#TENANTS[@]} tenant(s): ${TENANTS[*]}"
else
    TENANTS=("$1")
fi

# --- Deploy function ---

deploy_tenant() {
    local tenant="$1"
    local tenant_dir="${TENANTS_DIR}/${tenant}"

    echo ""
    info "=== Deploying ${tenant} ==="

    if [ ! -d "$tenant_dir" ]; then
        error "Tenant directory not found: ${tenant_dir}"
        return 1
    fi

    if [ ! -f "${tenant_dir}/docker-compose.yml" ]; then
        error "No docker-compose.yml found in ${tenant_dir}"
        return 1
    fi

    cd "$tenant_dir"

    # Detect mode from DB_HOST
    local mode="shared"
    if [ -f .env ]; then
        db_host=$(grep '^DB_HOST=' .env | cut -d= -f2- || true)
        [ "$db_host" != "shared-mysql" ] && mode="standalone"
    fi

    # Pull latest code
    info "Pulling latest code..."
    git pull || { error "git pull failed for ${tenant} — refusing to rebuild stale code."; return 1; }

    # Rebuild and recreate all containers.
    # Capture the exit code explicitly: this function runs inside an `if`
    # condition at the call site, which disables `set -e` for the whole body,
    # so a failed build would otherwise fall through to the health check and
    # report success against the previously-built (stale) images.
    #
    # APT_CACHE_BUST changes daily so the app image's `apt-get update && upgrade`
    # layer is never silently reused from Docker's build cache — otherwise OS
    # security patches published after the last build wouldn't land until
    # something else invalidated that layer.
    info "Rebuilding containers..."
    export APT_CACHE_BUST="$(date +%Y%m%d)"
    if [ "$mode" = "standalone" ]; then
        docker compose --profile standalone up -d --build || { error "Build/start failed for ${tenant} — old containers may still be running stale code."; return 1; }
    else
        docker compose up -d --build || { error "Build/start failed for ${tenant} — old containers may still be running stale code."; return 1; }
    fi

    # Wait for app to become healthy
    info "Waiting for app to become healthy..."
    local elapsed=0
    while [ $elapsed -lt $HEALTH_TIMEOUT ]; do
        if [ "$mode" = "standalone" ]; then
            status=$(docker compose --profile standalone ps app --format '{{.Status}}' 2>/dev/null || echo "")
        else
            status=$(docker compose ps app --format '{{.Status}}' 2>/dev/null || echo "")
        fi
        if echo "$status" | grep -q "(healthy)"; then
            break
        fi
        sleep 5
        elapsed=$((elapsed + 5))
    done

    if [ $elapsed -ge $HEALTH_TIMEOUT ]; then
        error "App did not become healthy within ${HEALTH_TIMEOUT}s."
        error "Check logs: cd ${tenant_dir} && docker compose logs app"
        return 1
    fi

    ok "${tenant} deployed successfully."
}

# --- Run ---

FAILED=0
SUCCEEDED=0

for tenant in "${TENANTS[@]}"; do
    if deploy_tenant "$tenant"; then
        SUCCEEDED=$((SUCCEEDED + 1))
    else
        FAILED=$((FAILED + 1))
        warn "Deployment failed for ${tenant} — continuing with remaining tenants."
    fi
done

echo ""
echo "==========================================="
echo "  Deployment complete"
echo "  Succeeded: ${SUCCEEDED}"
[ "$FAILED" -gt 0 ] && echo "  Failed:    ${FAILED}"
echo "==========================================="

[ "$FAILED" -gt 0 ] && exit 1
exit 0
