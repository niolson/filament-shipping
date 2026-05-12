#!/bin/bash
set -euo pipefail

# PolyBag Tenant Deprovisioning Script
# Usage: ./scripts/deprovision-tenant.sh [--force] <tenant-name>
#
# Destroys all resources for a tenant: containers, images, volumes, database,
# Redis keys, Caddy route, and the tenant directory. This is irreversible.
#
# Examples:
#   ./scripts/deprovision-tenant.sh acme
#   ./scripts/deprovision-tenant.sh --force acme   # skip confirmation prompt

TENANTS_DIR="/opt/tenants"
CADDY_DIR="/opt/caddy"
SHARED_DIR="/opt/shared"

# --- Helpers ---

info()  { echo -e "\033[1;34m[INFO]\033[0m  $*"; }
warn()  { echo -e "\033[1;33m[WARN]\033[0m  $*"; }
error() { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; }
ok()    { echo -e "\033[1;32m[OK]\033[0m    $*"; }

# --- Parse arguments ---

FORCE=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --force)
            FORCE=true
            shift
            ;;
        -*)
            error "Unknown option: $1"
            exit 1
            ;;
        *)
            break
            ;;
    esac
done

if [ $# -lt 1 ]; then
    error "Usage: $0 [--force] <tenant-name>"
    exit 1
fi

TENANT="$1"
TENANT_DIR="${TENANTS_DIR}/${TENANT}"

# --- Validate ---

if [ ! -d "$TENANT_DIR" ]; then
    error "Tenant directory not found: ${TENANT_DIR}"
    exit 1
fi

if [ ! -f "${TENANT_DIR}/.env" ]; then
    error "No .env found in ${TENANT_DIR} — cannot determine configuration."
    exit 1
fi

# Detect mode from DB_HOST
DB_HOST=$(grep '^DB_HOST=' "${TENANT_DIR}/.env" | cut -d= -f2-)
if [ "$DB_HOST" = "shared-mysql" ]; then
    MODE="shared"
else
    MODE="standalone"
fi

DOMAIN=$(grep '^APP_URL=' "${TENANT_DIR}/.env" | cut -d= -f2- | sed 's|https://||')

# --- Confirmation ---

echo ""
warn "This will permanently destroy the '${TENANT}' tenant:"
echo "  Mode:      ${MODE}"
echo "  Domain:    ${DOMAIN}"
echo "  Directory: ${TENANT_DIR}"
if [ "$MODE" = "shared" ]; then
    DB_NAME=$(grep '^DB_DATABASE=' "${TENANT_DIR}/.env" | cut -d= -f2-)
    REDIS_PREFIX=$(grep '^REDIS_PREFIX=' "${TENANT_DIR}/.env" | cut -d= -f2-)
    echo "  Database:  ${DB_NAME} @ shared-mysql"
    echo "  Redis:     shared-redis (prefix: ${REDIS_PREFIX})"
fi
echo ""

if [ "$FORCE" = false ]; then
    read -r -p "Type the tenant name to confirm: " CONFIRM
    if [ "$CONFIRM" != "$TENANT" ]; then
        error "Confirmation did not match. Aborting."
        exit 1
    fi
fi

# --- Stop and remove containers ---

info "Stopping and removing containers..."
cd "$TENANT_DIR"
if [ "$MODE" = "standalone" ]; then
    docker compose --profile standalone down -v --rmi all --remove-orphans 2>/dev/null || true
else
    docker compose down --rmi all --remove-orphans 2>/dev/null || true
fi
ok "Containers removed."

# --- Shared mode: drop MySQL database and Redis keys ---

if [ "$MODE" = "shared" ]; then
    DB_NAME=$(grep '^DB_DATABASE=' "${TENANT_DIR}/.env" | cut -d= -f2-)
    DB_USER=$(grep '^DB_USERNAME=' "${TENANT_DIR}/.env" | cut -d= -f2-)
    REDIS_PREFIX=$(grep '^REDIS_PREFIX=' "${TENANT_DIR}/.env" | cut -d= -f2-)

    if [ ! -f "${SHARED_DIR}/.env" ]; then
        error "Shared .env not found at ${SHARED_DIR}/.env — cannot drop MySQL database or Redis keys."
        error "Remove them manually, then delete ${TENANT_DIR}."
        exit 1
    fi

    SHARED_MYSQL_ROOT_PASS=$(grep '^MYSQL_ROOT_PASSWORD=' "${SHARED_DIR}/.env" | cut -d= -f2-)
    SHARED_REDIS_PASS=$(grep '^REDIS_PASSWORD=' "${SHARED_DIR}/.env" | cut -d= -f2-)

    info "Dropping database '${DB_NAME}' and user '${DB_USER}' from shared-mysql..."
    docker exec shared-mysql mysql -uroot -p"${SHARED_MYSQL_ROOT_PASS}" -e "
        DROP DATABASE IF EXISTS \`${DB_NAME}\`;
        DROP USER IF EXISTS '${DB_USER}'@'%';
        FLUSH PRIVILEGES;
    "
    ok "Database and user dropped."

    info "Flushing Redis keys with prefix '${REDIS_PREFIX}'..."
    KEY_COUNT=$(docker exec shared-redis redis-cli -a "${SHARED_REDIS_PASS}" --no-auth-warning \
        --scan --pattern "${REDIS_PREFIX}*" | wc -l)
    if [ "$KEY_COUNT" -gt 0 ]; then
        docker exec shared-redis redis-cli -a "${SHARED_REDIS_PASS}" --no-auth-warning \
            --scan --pattern "${REDIS_PREFIX}*" | \
            xargs -r docker exec -i shared-redis redis-cli -a "${SHARED_REDIS_PASS}" --no-auth-warning DEL
        ok "Flushed ${KEY_COUNT} Redis keys."
    else
        ok "No Redis keys found for prefix '${REDIS_PREFIX}'."
    fi
fi

# --- Remove Caddy route ---

CADDY_FILE="${CADDY_DIR}/Caddyfile"
if [ -f "$CADDY_FILE" ] && grep -q "$DOMAIN" "$CADDY_FILE"; then
    info "Removing Caddy route for ${DOMAIN}..."
    # Remove the block: blank line before, domain line, contents, closing brace
    awk -v domain="$DOMAIN" '
        /^[[:space:]]*$/ { blank=$0; next }
        $0 ~ "^"domain" \\{" { in_block=1; blank=""; next }
        in_block && /^\}/ { in_block=0; next }
        in_block { next }
        { if (blank != "") { print blank; blank="" } print }
    ' "$CADDY_FILE" > "${CADDY_FILE}.tmp" && mv "${CADDY_FILE}.tmp" "$CADDY_FILE"

    info "Reloading Caddy..."
    docker compose -f "${CADDY_DIR}/docker-compose.yml" exec caddy caddy reload --config /etc/caddy/Caddyfile
    ok "Caddy route removed."
else
    warn "No Caddy entry found for ${DOMAIN} — skipping."
fi

# --- Delete tenant directory ---

info "Deleting ${TENANT_DIR}..."
rm -rf "$TENANT_DIR"
ok "Directory deleted."

echo ""
echo "==========================================="
echo "  Tenant deprovisioned: ${TENANT}"
echo "==========================================="
echo ""
