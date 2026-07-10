#!/bin/bash
set -euo pipefail

# PolyBag Tenant Provisioning Script
# Usage: ./scripts/provision-tenant.sh [--mode shared|standalone] <tenant-name> [domain]
#
# Modes:
#   shared     - Uses shared MySQL + Redis from /opt/shared/ (default)
#   standalone - Per-tenant MySQL + Redis containers (for on-prem)
#
# Examples:
#   ./scripts/provision-tenant.sh acme                          # shared mode, acme.polybag.app
#   ./scripts/provision-tenant.sh --mode standalone acme        # standalone mode
#   ./scripts/provision-tenant.sh acme acme.example.com         # shared mode, custom domain

REPO_URL="https://github.com/niolson/polybag.git"
TENANTS_DIR="/opt/tenants"
CADDY_DIR="/opt/caddy"
SHARED_DIR="/opt/shared"
SHARED_QZ_DIR="${SHARED_DIR}/qz"
DEFAULT_DOMAIN_SUFFIX="polybag.app"

# --- Helpers ---

info()  { echo -e "\033[1;34m[INFO]\033[0m  $*"; }
error() { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; }
ok()    { echo -e "\033[1;32m[OK]\033[0m    $*"; }

generate_password() {
    openssl rand -hex 16
}

# --- Parse arguments ---

MODE="shared"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --mode)
            MODE="$2"
            shift 2
            ;;
        --mode=*)
            MODE="${1#*=}"
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

if [[ "$MODE" != "shared" && "$MODE" != "standalone" ]]; then
    error "Invalid mode: ${MODE}. Must be 'shared' or 'standalone'."
    exit 1
fi

# --- Validate ---

if [ $# -lt 1 ]; then
    error "Usage: $0 [--mode shared|standalone] <tenant-name> [domain]"
    exit 1
fi

TENANT="$1"
DOMAIN="${2:-${TENANT}.${DEFAULT_DOMAIN_SUFFIX}}"
TENANT_DIR="${TENANTS_DIR}/${TENANT}"
# Per-tenant shared network (shared mode): isolates this tenant's containers from
# other tenants while the shared datastores attach to all such networks.
TENANT_NETWORK="shared-${TENANT}"
# Shared datastores every tenant reaches container-to-container (not polybag-connect,
# which is reached via its public URL).
SHARED_SERVICES=(shared-mysql shared-redis gotenberg)

if [ -d "$TENANT_DIR" ]; then
    error "Tenant directory already exists: ${TENANT_DIR}"
    exit 1
fi

if ! docker network inspect proxy &>/dev/null; then
    error "Docker network 'proxy' does not exist. Run: docker network create proxy"
    exit 1
fi

if ! docker network inspect shared &>/dev/null; then
    error "Docker network 'shared' does not exist. Run: docker network create shared"
    exit 1
fi

# --- Mode-specific validation ---

if [ "$MODE" = "shared" ]; then
    if [ ! -f "${SHARED_DIR}/.env" ]; then
        error "Shared infrastructure .env not found at ${SHARED_DIR}/.env"
        error "Set up shared infra first: cd ${SHARED_DIR} && docker compose up -d"
        exit 1
    fi

    if [ ! -f "${SHARED_DIR}/shared-secrets.env" ]; then
        error "Shared secrets file not found at ${SHARED_DIR}/shared-secrets.env"
        error "Copy infra/shared-secrets.env.example to ${SHARED_DIR}/shared-secrets.env and fill in values."
        exit 1
    fi

    if ! grep -q '^REDIS_PASSWORD=.' "${SHARED_DIR}/shared-secrets.env"; then
        error "REDIS_PASSWORD is not set in ${SHARED_DIR}/shared-secrets.env"
        exit 1
    fi

    # Verify shared containers are running
    if ! docker inspect shared-mysql --format '{{.State.Running}}' 2>/dev/null | grep -q true; then
        error "shared-mysql container is not running. Start shared infra first."
        exit 1
    fi
    if ! docker inspect shared-redis --format '{{.State.Running}}' 2>/dev/null | grep -q true; then
        error "shared-redis container is not running. Start shared infra first."
        exit 1
    fi
fi

# --- Clone ---

info "Cloning repo into ${TENANT_DIR}..."
git clone "$REPO_URL" "$TENANT_DIR"
cd "$TENANT_DIR"

# --- Environment ---

DB_PASSWORD=$(generate_password)
REDIS_PASSWORD=$(generate_password)

info "Creating .env (mode: ${MODE})..."
cp .env.example .env

# Common settings
sed -i "s|^APP_NAME=.*|APP_NAME=\"PolyBag\"|" .env
sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env
sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" .env
sed -i "s|^DB_CONNECTION=.*|DB_CONNECTION=mysql|" .env
sed -i "s|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=redis|" .env
sed -i "s|^SESSION_DRIVER=.*|SESSION_DRIVER=redis|" .env
# Tenants are always served over HTTPS (APP_URL above), so the session cookie
# must carry the `secure` flag. Set it explicitly rather than relying on the
# .env.example default (which stays false for plain-HTTP local dev). This is the
# mechanism whose absence let a tenant ship without it — see pentest issue 05.
sed -i "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|" .env
sed -i "s|^CACHE_STORE=.*|CACHE_STORE=redis|" .env
if grep -q '^SENTRY_ENVIRONMENT=' .env; then
    sed -i "s|^SENTRY_ENVIRONMENT=.*|SENTRY_ENVIRONMENT=${TENANT}|" .env
else
    echo "SENTRY_ENVIRONMENT=${TENANT}" >> .env
fi

if [ "$MODE" = "shared" ]; then
    # --- Shared mode: use shared-mysql and shared-redis ---

    SHARED_MYSQL_ROOT_PASS=$(grep '^MYSQL_ROOT_PASSWORD=' "${SHARED_DIR}/.env" | cut -d= -f2-)

    DB_NAME="polybag_${TENANT}"
    DB_USER="polybag_${TENANT}"

    # Check for prefix collision in shared Redis
    for existing_dir in "${TENANTS_DIR}"/*/; do
        if [ -f "${existing_dir}.env" ]; then
            existing_prefix=$(grep '^REDIS_PREFIX=' "${existing_dir}.env" | cut -d= -f2- || true)
            if [ "$existing_prefix" = "${TENANT}-" ]; then
                error "Redis prefix '${TENANT}-' already in use by another tenant."
                rm -rf "$TENANT_DIR"
                exit 1
            fi
        fi
    done

    # Create database and user in shared MySQL
    info "Creating database '${DB_NAME}' in shared-mysql..."
    docker exec shared-mysql mysql -uroot -p"${SHARED_MYSQL_ROOT_PASS}" -e "
        CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
        GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
        FLUSH PRIVILEGES;
    "
    ok "Database created."

    sed -i "s|^DB_HOST=.*|DB_HOST=shared-mysql|" .env
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" .env
    sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" .env
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env
    sed -i "s|^REDIS_HOST=.*|REDIS_HOST=shared-redis|" .env
    # REDIS_PASSWORD is injected at runtime from /opt/shared/shared-secrets.env via Docker env_file

    # Per-tenant network so this tenant is isolated from other tenants at the
    # network layer while still reaching the shared datastores (see issue 16).
    sed -i "s|^SHARED_NETWORK=.*|SHARED_NETWORK=${TENANT_NETWORK}|" .env

    info "Creating per-tenant network '${TENANT_NETWORK}' and attaching shared services..."
    docker network create "$TENANT_NETWORK" 2>/dev/null || true
    for svc in "${SHARED_SERVICES[@]}"; do
        if docker inspect "$svc" &>/dev/null; then
            docker network connect "$TENANT_NETWORK" "$svc" 2>/dev/null || true
        fi
    done

    # Set Redis prefix for tenant isolation
    sed -i "s|^# REDIS_PREFIX=.*|REDIS_PREFIX=${TENANT}-|" .env
    # If the line wasn't commented, update it directly
    sed -i "s|^REDIS_PREFIX=.*|REDIS_PREFIX=${TENANT}-|" .env
    sed -i "s|^# CACHE_PREFIX=.*|CACHE_PREFIX=${TENANT}-cache-|" .env
    sed -i "s|^CACHE_PREFIX=.*|CACHE_PREFIX=${TENANT}-cache-|" .env

    # Point QZ cert volumes at shared location
    echo "" >> .env
    echo "# QZ Tray certificate paths (shared)" >> .env
    echo "QZ_PRIVATE_KEY_PATH=${SHARED_QZ_DIR}/qz-private-key.pem" >> .env
    echo "QZ_CERTIFICATE_PATH=${SHARED_QZ_DIR}/qz-certificate.pem" >> .env

else
    # --- Standalone mode: per-tenant MySQL + Redis ---

    sed -i "s|^DB_HOST=.*|DB_HOST=mysql|" .env
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=polybag|" .env
    sed -i "s|^DB_USERNAME=.*|DB_USERNAME=polybag|" .env
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env
    sed -i "s|^REDIS_HOST=.*|REDIS_HOST=redis|" .env
    sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=${REDIS_PASSWORD}|" .env
fi

# --- QZ Tray Certificate ---

if [ -f "${SHARED_QZ_DIR}/qz-private-key.pem" ] && [ -f "${SHARED_QZ_DIR}/qz-certificate.pem" ]; then
    if [ "$MODE" = "standalone" ]; then
        info "Copying shared QZ Tray certificate..."
        mkdir -p storage/app/private
        cp "${SHARED_QZ_DIR}/qz-private-key.pem" storage/app/private/qz-private-key.pem
        cp "${SHARED_QZ_DIR}/qz-certificate.pem" public/qz-certificate.pem
        ok "QZ Tray certificate copied."
    else
        ok "QZ Tray certificate will be mounted from shared location."
    fi
else
    info "No shared QZ Tray certificate found at ${SHARED_QZ_DIR}."
    info "Generate one after setup: docker compose exec -it app php artisan app:generate-qz-cert"
fi

# --- OAuth Broker + Google SSO ---
# In shared mode these are injected at runtime from /opt/shared/shared-secrets.env via Docker
# env_file, so there is nothing to write into the tenant .env here.
# In standalone mode we attempt to copy from shared files if available.

if [ "$MODE" = "standalone" ]; then
    OAUTH_SECRET=""

    if [ -f "${SHARED_DIR}/shared-secrets.env" ]; then
        OAUTH_SECRET=$(grep '^OAUTH_BROKER_SECRET=' "${SHARED_DIR}/shared-secrets.env" | cut -d= -f2- || true)
    elif [ -f "/opt/polybag-connect/.env" ]; then
        OAUTH_SECRET=$(grep '^SHARED_TENANT_SECRET=' "/opt/polybag-connect/.env" | cut -d= -f2- || true)
    fi

    if [ -n "$OAUTH_SECRET" ]; then
        info "Adding OAuth broker configuration..."
        sed -i "s|^OAUTH_BROKER_SECRET=.*|OAUTH_BROKER_SECRET=${OAUTH_SECRET}|" .env
        sed -i "s|^OAUTH_BROKER_URL=.*|OAUTH_BROKER_URL=https://connect.${DEFAULT_DOMAIN_SUFFIX}|" .env
        sed -i "s|^OAUTH_INSTANCE_ID=.*|OAUTH_INSTANCE_ID=${TENANT}.${DEFAULT_DOMAIN_SUFFIX}|" .env
        ok "OAuth broker configuration added."
    else
        info "No OAuth secret found — set OAUTH_BROKER_SECRET in .env manually if needed."
    fi

    GOOGLE_CID=$(grep '^GOOGLE_CLIENT_ID=' "${SHARED_DIR}/shared-secrets.env" 2>/dev/null | cut -d= -f2- || true)
    GOOGLE_SEC=$(grep '^GOOGLE_CLIENT_SECRET=' "${SHARED_DIR}/shared-secrets.env" 2>/dev/null | cut -d= -f2- || true)

    if [ -n "$GOOGLE_CID" ] && [ -n "$GOOGLE_SEC" ]; then
        info "Adding Google SSO credentials..."
        sed -i "s|^GOOGLE_CLIENT_ID=.*|GOOGLE_CLIENT_ID=${GOOGLE_CID}|" .env
        sed -i "s|^GOOGLE_CLIENT_SECRET=.*|GOOGLE_CLIENT_SECRET=${GOOGLE_SEC}|" .env
        ok "Google SSO credentials added. Remember to register https://${DOMAIN}/auth/google/callback in Google Console."
    else
        info "No Google SSO credentials found — set GOOGLE_CLIENT_ID/SECRET in .env manually if needed."
    fi
else
    # Shared mode: OAUTH_BROKER_URL and OAUTH_INSTANCE_ID are tenant-specific and still need to be set.
    sed -i "s|^OAUTH_BROKER_URL=.*|OAUTH_BROKER_URL=https://connect.${DEFAULT_DOMAIN_SUFFIX}|" .env
    sed -i "s|^OAUTH_INSTANCE_ID=.*|OAUTH_INSTANCE_ID=${TENANT}.${DEFAULT_DOMAIN_SUFFIX}|" .env
    info "OAUTH_BROKER_SECRET, REDIS_PASSWORD, GOOGLE_CLIENT_ID/SECRET will be injected from ${SHARED_DIR}/shared-secrets.env at runtime."
fi

# Pre-create SSH key directory so Docker doesn't create it as root.
# www-data inside the container needs write access; the key files
# themselves are protected by their own 600 permissions.
mkdir -p storage/app/private/ssh
chmod 777 storage/app/private/ssh

# --- Build & Start ---

info "Building and starting containers (${MODE} mode)..."
if [ "$MODE" = "standalone" ]; then
    docker compose --profile standalone up -d --build
else
    docker compose up -d --build
fi

info "Waiting for app to become healthy..."
timeout=120
elapsed=0
while [ $elapsed -lt $timeout ]; do
    if [ "$MODE" = "standalone" ]; then
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

if [ $elapsed -ge $timeout ]; then
    error "App container did not become healthy within ${timeout}s."
    error "Check logs: cd ${TENANT_DIR} && docker compose logs app"
    exit 1
fi

ok "Containers running."

# --- Generate App Key ---

info "Generating application key..."
APP_KEY="base64:$(openssl rand -base64 32)"
sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env

# Restart to pick up new key (entrypoint runs optimize)
# nginx is also restarted so it re-resolves the app container's IP after recreation
if [ "$MODE" = "standalone" ]; then
    docker compose --profile standalone up -d --force-recreate app queue nginx
else
    docker compose up -d --force-recreate app queue nginx
fi

info "Waiting for app to become healthy after key rotation..."
timeout=120
elapsed=0
while [ $elapsed -lt $timeout ]; do
    if [ "$MODE" = "standalone" ]; then
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

if [ $elapsed -ge $timeout ]; then
    error "App container did not become healthy within ${timeout}s after key rotation."
    error "Check logs: cd ${TENANT_DIR} && docker compose logs app"
    exit 1
fi

ok "App key generated."

# --- SSH Key for Import Tunneling ---
info "Generating SSH keypair for import tunneling..."
docker compose exec --user www-data app php artisan app:generate-ssh-key --force
ok "SSH keypair generated."

# --- Caddy ---

info "Adding Caddy route for ${DOMAIN}..."
cat >> "${CADDY_DIR}/Caddyfile" << EOF

${DOMAIN} {
    import cf_origin
    reverse_proxy ${TENANT}-nginx-1:80
}
EOF

info "Reloading Caddy..."
docker compose -f "${CADDY_DIR}/docker-compose.yml" exec caddy caddy reload --config /etc/caddy/Caddyfile
ok "Caddy reloaded."

# --- Enable per-hostname Authenticated Origin Pulls (Cloudflare) -------------
# Only relevant for Cloudflare-fronted polybag.app tenants. Skipped if AOP isn't
# configured, so standalone/on-prem and custom-domain provisioning are untouched.
CF_API_TOKEN=$(grep '^CF_API_TOKEN=' "${SHARED_DIR}/shared-secrets.env" 2>/dev/null | cut -d= -f2- || true)
CF_ZONE_ID=$(grep '^CF_ZONE_ID=' "${SHARED_DIR}/shared-secrets.env" 2>/dev/null | cut -d= -f2- || true)
AOP_CERT_ID=$(grep '^AOP_CERT_ID=' "${SHARED_DIR}/shared-secrets.env" 2>/dev/null | cut -d= -f2- || true)

if [[ "${MODE}" == "shared" && -n "${CF_API_TOKEN}" && -n "${CF_ZONE_ID}" && -n "${AOP_CERT_ID}" ]]; then
    info "Enabling Authenticated Origin Pulls for ${DOMAIN}..."

    # Rebuild the FULL set of *.polybag.app hostnames from the Caddyfile and PUT
    # it whole: correct whether Cloudflare's PUT replaces or merges associations,
    # and a re-run self-heals. Custom-domain tenants are in other zones, excluded.
    mapfile -t AOP_HOSTS < <(
        grep -oE '^[a-z0-9][a-z0-9.-]*\.polybag\.app' "${CADDY_DIR}/Caddyfile" | sort -u
    )

    if [[ "${#AOP_HOSTS[@]}" -eq 0 ]]; then
        error "No polybag.app hostnames found in Caddyfile — skipping AOP (unexpected)."
    else
        AOP_CONFIG=$(printf '%s\n' "${AOP_HOSTS[@]}" \
            | jq -R . \
            | jq -s --arg cert "${AOP_CERT_ID}" \
                '[.[] | {hostname: ., cert_id: $cert, enabled: true}]')

        AOP_RESP=$(mktemp)
        HTTP_CODE=$(curl -sS -o "${AOP_RESP}" -w '%{http_code}' \
            -X PUT "https://api.cloudflare.com/client/v4/zones/${CF_ZONE_ID}/origin_tls_client_auth/hostnames" \
            -H "Authorization: Bearer ${CF_API_TOKEN}" \
            -H "Content-Type: application/json" \
            -d "{\"config\": ${AOP_CONFIG}}")

        if [[ "${HTTP_CODE}" == "200" ]] && jq -e '.success == true' "${AOP_RESP}" >/dev/null 2>&1; then
            ok "AOP enabled for ${#AOP_HOSTS[@]} hostname(s)."
        else
            error "AOP enable FAILED (HTTP ${HTTP_CODE}):"
            jq -r '.errors // .' "${AOP_RESP}" >&2 2>/dev/null || cat "${AOP_RESP}" >&2
            error "Tenant ${DOMAIN} is live but NOT AOP-protected — fix before"
            error "relying on the firewall, or it is reachable via the CF bypass."
        fi
        rm -f "${AOP_RESP}"
    fi
else
    info "Skipping AOP enablement (not shared mode, or AOP env not configured)."
fi

# --- Summary ---

echo ""
echo "==========================================="
echo "  Tenant provisioned: ${TENANT}"
echo "  Mode:    ${MODE}"
echo "  Domain:  https://${DOMAIN}"
echo "  Dir:     ${TENANT_DIR}"
if [ "$MODE" = "shared" ]; then
echo "  DB:      polybag_${TENANT} @ shared-mysql"
echo "  Redis:   shared-redis (prefix: ${TENANT}-)"
fi
echo "==========================================="
echo ""
echo "Next steps:"
echo "  1. Create the first admin user:"
echo "     cd ${TENANT_DIR}"
if [ "$MODE" = "standalone" ]; then
echo "     docker compose --profile standalone exec -it app php artisan app:create-user"
else
echo "     docker compose exec -it app php artisan app:create-user"
fi
echo ""
echo "  2. Log in at https://${DOMAIN}"
echo ""
