#!/bin/bash
set -euo pipefail

# PolyBag Internal Secret Rotation
# Rotates all server-managed secrets in one pass:
#   - REDIS_PASSWORD        (/opt/shared/.env + shared-secrets.env + live Redis)
#   - MYSQL_ROOT_PASSWORD   (/opt/shared/.env + live MySQL)
#   - OAUTH_BROKER_SECRET   (shared-secrets.env + polybag-connect .env, if present)
#   - DB_PASSWORD           (each shared-mode tenant's .env + live MySQL)
#
# Standalone tenants are skipped — their per-tenant MySQL and Redis require
# separate handling.
#
# Does NOT rotate: APP_KEY, BACKUP_ENCRYPTION_KEY, or any external API credentials
# (Google, USPS, carriers). Those must be rotated manually via their respective
# service consoles.
#
# Usage:
#   ./scripts/rotate-internal-secrets.sh          # prompts for confirmation
#   ./scripts/rotate-internal-secrets.sh --yes    # skip confirmation
#   ./scripts/rotate-internal-secrets.sh --dry-run

SHARED_DIR="/opt/shared"
TENANTS_DIR="/opt/tenants"
CONNECT_DIR="/opt/polybag-connect"

# --- Helpers ---

info()  { echo -e "\033[1;34m[INFO]\033[0m  $*"; }
ok()    { echo -e "\033[1;32m[OK]\033[0m    $*"; }
warn()  { echo -e "\033[1;33m[WARN]\033[0m  $*"; }
error() { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; }
step()  { echo -e "\n\033[1;36m[STEP]\033[0m  $*"; }

generate_password() { openssl rand -hex 16; }

set_env() {
    local file="$1" key="$2" value="$3"
    sed -i "s|^${key}=.*|${key}=${value}|" "$file"
}

# --- Parse arguments ---

DRY_RUN=false
YES=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=true; shift ;;
        --yes)     YES=true; shift ;;
        *) error "Unknown option: $1"; exit 1 ;;
    esac
done

$DRY_RUN && info "Dry run — no changes will be made."

# --- Validate prerequisites ---

for f in "${SHARED_DIR}/.env" "${SHARED_DIR}/shared-secrets.env"; do
    if [ ! -f "$f" ]; then
        error "Required file not found: $f"
        exit 1
    fi
done

if ! docker inspect shared-mysql --format '{{.State.Running}}' 2>/dev/null | grep -q true; then
    error "shared-mysql is not running."
    exit 1
fi
if ! docker inspect shared-redis --format '{{.State.Running}}' 2>/dev/null | grep -q true; then
    error "shared-redis is not running."
    exit 1
fi

HAS_CONNECT=false
if [ -d "$CONNECT_DIR" ] && [ -f "${CONNECT_DIR}/.env" ]; then
    HAS_CONNECT=true
fi

# --- Snapshot current values ---

OLD_REDIS=$(grep '^REDIS_PASSWORD=' "${SHARED_DIR}/shared-secrets.env" | cut -d= -f2-)
OLD_MYSQL_ROOT=$(grep '^MYSQL_ROOT_PASSWORD=' "${SHARED_DIR}/.env" | cut -d= -f2-)

[ -n "$OLD_REDIS" ]      || { error "REDIS_PASSWORD not set in ${SHARED_DIR}/shared-secrets.env"; exit 1; }
[ -n "$OLD_MYSQL_ROOT" ] || { error "MYSQL_ROOT_PASSWORD not set in ${SHARED_DIR}/.env"; exit 1; }

# --- Generate new values ---

NEW_REDIS=$(generate_password)
NEW_MYSQL_ROOT=$(generate_password)
NEW_OAUTH=$(generate_password)

# Build list of shared-mode tenants and generate a new DB password for each
SHARED_TENANTS=()
declare -A TENANT_DB_USER
declare -A TENANT_NEW_PASS

for tenant_dir in "${TENANTS_DIR}"/*/; do
    [ -f "${tenant_dir}.env" ] || continue
    [ -f "${tenant_dir}docker-compose.yml" ] || continue
    tenant=$(basename "$tenant_dir")
    db_host=$(grep '^DB_HOST=' "${tenant_dir}.env" | cut -d= -f2- || true)
    if [ "$db_host" = "shared-mysql" ]; then
        SHARED_TENANTS+=("$tenant")
        TENANT_DB_USER[$tenant]=$(grep '^DB_USERNAME=' "${tenant_dir}.env" | cut -d= -f2-)
        TENANT_NEW_PASS[$tenant]=$(generate_password)
    else
        warn "Skipping standalone tenant '${tenant}' — rotate its secrets separately."
    fi
done

# --- Summary ---

echo ""
echo "Will rotate:"
echo "  REDIS_PASSWORD       → ${SHARED_DIR}/shared-secrets.env + ${SHARED_DIR}/.env + live Redis"
echo "  MYSQL_ROOT_PASSWORD  → ${SHARED_DIR}/.env + live MySQL"
if $HAS_CONNECT; then
    echo "  OAUTH_BROKER_SECRET  → ${SHARED_DIR}/shared-secrets.env + ${CONNECT_DIR}/.env"
else
    echo "  OAUTH_BROKER_SECRET  → ${SHARED_DIR}/shared-secrets.env only"
    warn "polybag-connect not found at ${CONNECT_DIR} — update its SHARED_TENANT_SECRET manually."
fi
if [ ${#SHARED_TENANTS[@]} -gt 0 ]; then
    echo "  DB_PASSWORD          → ${#SHARED_TENANTS[@]} shared tenant(s): ${SHARED_TENANTS[*]}"
else
    echo "  DB_PASSWORD          → (no shared tenants found)"
fi
echo ""

if $DRY_RUN; then
    ok "Dry run complete — no changes made."
    exit 0
fi

# --- Confirmation ---

if ! $YES; then
    read -r -p "Proceed with rotation? This will briefly interrupt Redis connections. [y/N] " CONFIRM
    [[ "$CONFIRM" =~ ^[Yy]$ ]] || { info "Aborted."; exit 0; }
fi

# --- Step 1: Update all files first ---

step "Updating secret files"

set_env "${SHARED_DIR}/.env" "MYSQL_ROOT_PASSWORD" "$NEW_MYSQL_ROOT"
set_env "${SHARED_DIR}/.env" "REDIS_PASSWORD" "$NEW_REDIS"
set_env "${SHARED_DIR}/shared-secrets.env" "REDIS_PASSWORD" "$NEW_REDIS"
set_env "${SHARED_DIR}/shared-secrets.env" "OAUTH_BROKER_SECRET" "$NEW_OAUTH"

if $HAS_CONNECT; then
    set_env "${CONNECT_DIR}/.env" "SHARED_TENANT_SECRET" "$NEW_OAUTH"
fi

if [ ${#SHARED_TENANTS[@]} -gt 0 ]; then
    for tenant in "${SHARED_TENANTS[@]}"; do
        set_env "${TENANTS_DIR}/${tenant}/.env" "DB_PASSWORD" "${TENANT_NEW_PASS[$tenant]}"
    done
fi

ok "Files updated."

# --- Step 2: Rotate MySQL root password ---

step "Rotating MySQL root password"

docker exec shared-mysql mysql -uroot -p"${OLD_MYSQL_ROOT}" -e "
    ALTER USER 'root'@'localhost' IDENTIFIED BY '${NEW_MYSQL_ROOT}';
    ALTER USER IF EXISTS 'root'@'%' IDENTIFIED BY '${NEW_MYSQL_ROOT}';
    FLUSH PRIVILEGES;
"
ok "MySQL root password rotated."

# --- Step 3: Rotate tenant DB passwords ---

if [ ${#SHARED_TENANTS[@]} -gt 0 ]; then
    step "Rotating tenant DB passwords"
    for tenant in "${SHARED_TENANTS[@]}"; do
        docker exec shared-mysql mysql -uroot -p"${NEW_MYSQL_ROOT}" -e "
            ALTER USER '${TENANT_DB_USER[$tenant]}'@'%' IDENTIFIED BY '${TENANT_NEW_PASS[$tenant]}';
            FLUSH PRIVILEGES;
        "
        ok "  ${tenant}"
    done
fi

# --- Step 4: Restart polybag-connect ---

if $HAS_CONNECT; then
    step "Restarting polybag-connect"
    (cd "$CONNECT_DIR" && docker compose up -d --force-recreate)
    ok "polybag-connect restarted."
fi

# --- Step 5: Rotate Redis password then immediately restart all tenant containers ---
#
# CONFIG SET takes effect instantly — running containers holding the old password
# will fail to connect to Redis until restarted. We restart all tenants in parallel
# right after to keep the window as short as possible.

step "Rotating Redis password"
docker exec shared-redis redis-cli -a "${OLD_REDIS}" --no-auth-warning \
    CONFIG SET requirepass "${NEW_REDIS}"
ok "Redis requirepass updated."

if [ ${#SHARED_TENANTS[@]} -gt 0 ]; then
    step "Restarting all shared tenant containers in parallel"
    PIDS=()
    for tenant in "${SHARED_TENANTS[@]}"; do
        (
            cd "${TENANTS_DIR}/${tenant}"
            docker compose up -d --force-recreate app queue scheduler nginx 2>&1 | \
                sed "s/^/  [${tenant}] /"
        ) &
        PIDS+=($!)
    done

    FAILED=0
    for i in "${!PIDS[@]}"; do
        wait "${PIDS[$i]}" || {
            error "Restart failed for tenant: ${SHARED_TENANTS[$i]}"
            FAILED=$((FAILED + 1))
        }
    done

    if [ "$FAILED" -gt 0 ]; then
        error "${FAILED} tenant(s) failed to restart — Redis connections may be broken."
        error "Run: cd /opt/tenants/<tenant> && docker compose up -d --force-recreate app queue scheduler"
        exit 1
    fi

    ok "All tenant containers restarted."
fi

# --- Done ---

echo ""
echo "==========================================="
echo "  Rotation complete"
echo "  REDIS_PASSWORD        ✓"
echo "  MYSQL_ROOT_PASSWORD   ✓"
echo "  OAUTH_BROKER_SECRET   ✓"
[ ${#SHARED_TENANTS[@]} -gt 0 ] && echo "  DB_PASSWORD           ✓ (${#SHARED_TENANTS[@]} tenant(s))"
echo "==========================================="
echo ""
