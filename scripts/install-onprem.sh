#!/bin/bash
set -euo pipefail

# PolyBag On-Premise Installer
# Single-tenant install with standalone MySQL + Redis containers.
#
# Usage: ./scripts/install-onprem.sh

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# --- Helpers ---

info()  { echo -e "\033[1;34m[INFO]\033[0m  $*"; }
error() { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; }
ok()    { echo -e "\033[1;32m[OK]\033[0m    $*"; }

generate_password() {
    openssl rand -hex 16
}

cd "$PROJECT_DIR"

# --- Pre-flight checks ---

if ! command -v docker &>/dev/null; then
    error "Docker is not installed. Install it first: https://docs.docker.com/engine/install/"
    exit 1
fi

if ! docker compose version &>/dev/null; then
    error "Docker Compose plugin is not installed."
    exit 1
fi

# --- Gather input ---

echo ""
echo "=== PolyBag On-Premise Installer ==="
echo ""

if [ -f .env ]; then
    info ".env already exists. Skipping environment setup."
    SKIP_ENV=true
else
    SKIP_ENV=false

    read -rp "Enter your domain or IP address (e.g. polybag.example.com or 192.168.1.100:8080): " APP_HOST

    if [ -z "$APP_HOST" ]; then
        error "Domain/IP is required."
        exit 1
    fi

    # Extract port if provided (e.g. 192.168.1.100:8080)
    if [[ "$APP_HOST" == *:* ]]; then
        APP_PORT="${APP_HOST##*:}"
        APP_HOST="${APP_HOST%%:*}"
    fi

    # Determine protocol — IPs get http, domains get https
    if [[ "$APP_HOST" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        if [ -n "${APP_PORT:-}" ] && [ "$APP_PORT" != "80" ]; then
            APP_URL="http://${APP_HOST}:${APP_PORT}"
        else
            APP_URL="http://${APP_HOST}"
        fi
    else
        if [ -n "${APP_PORT:-}" ] && [ "$APP_PORT" != "443" ]; then
            APP_URL="https://${APP_HOST}:${APP_PORT}"
        else
            APP_URL="https://${APP_HOST}"
        fi
    fi
fi

# --- Create .env ---

if [ "$SKIP_ENV" = false ]; then
    DB_PASSWORD=$(generate_password)
    REDIS_PASSWORD=$(generate_password)

    info "Creating .env..."
    cp .env.example .env

    sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
    sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env
    sed -i "s|^APP_URL=.*|APP_URL=${APP_URL}|" .env
    sed -i "s|^DB_HOST=.*|DB_HOST=mysql|" .env
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=polybag|" .env
    sed -i "s|^DB_USERNAME=.*|DB_USERNAME=polybag|" .env
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env
    sed -i "s|^REDIS_HOST=.*|REDIS_HOST=redis|" .env
    sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=${REDIS_PASSWORD}|" .env
    sed -i "s|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=redis|" .env
    sed -i "s|^SESSION_DRIVER=.*|SESSION_DRIVER=redis|" .env
    # Match the secure-cookie flag to the resolved protocol: HTTPS installs must
    # set it (so the session cookie carries `secure`), HTTP (IP) installs must
    # not (or login breaks over plain HTTP). See pentest issue 05.
    if [[ "$APP_URL" == https://* ]]; then
        sed -i "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|" .env
    else
        sed -i "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=false|" .env
    fi
    sed -i "s|^CACHE_STORE=.*|CACHE_STORE=redis|" .env
    sed -i "s|^GOTENBERG_URL=.*|GOTENBERG_URL=http://gotenberg:3000|" .env

    # Generate the app key here, before the first container start, so the
    # entrypoint's `optimize` caches the real key on its very first run. This
    # used to happen after the stack was up and required recreating the app
    # container to take effect, which raced with the initial migration.
    # Keeping it inside this branch also means re-running the installer over an
    # existing .env no longer rotates the key out from under encrypted data.
    sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env

    # Set custom port if specified
    if [ -n "${APP_PORT:-}" ] && [ "$APP_PORT" != "80" ]; then
        sed -i "s|^# APP_PORT=.*|APP_PORT=${APP_PORT}|" .env
        sed -i "s|^APP_PORT=.*|APP_PORT=${APP_PORT}|" .env
    fi

    ok ".env created."
fi

# --- Docker network ---

if ! docker network inspect proxy &>/dev/null; then
    info "Creating Docker network 'proxy'..."
    docker network create proxy
    ok "Network 'proxy' created."
fi

if ! docker network inspect shared &>/dev/null; then
    info "Creating Docker network 'shared'..."
    docker network create shared
    ok "Network 'shared' created."
fi

# --- Build & Start ---

# Create placeholder QZ cert files so Docker doesn't mount them as directories
mkdir -p storage/app/private
touch storage/app/private/qz-private-key.pem
touch public/qz-certificate.pem

# MySQL reads its three encryption config files straight out of this checkout
# through read-only bind mounts, as the mysql user (uid 999). If it cannot read
# the one landing in conf.d it does not fail — it silently discards the entire
# include directory and starts with encryption OFF, looking perfectly healthy.
# A checkout made under a restrictive umask produces exactly that, so make the
# files world-readable before first start. Checking the resulting mode rather
# than the chmod's exit status keeps this working when the files are already
# 644 but owned by another user.
info "Checking MySQL encryption config permissions..."
for cnf in infra/shared/mysql.cnf infra/shared/mysqld.my infra/shared/component_keyring_file.cnf; do
    if [ ! -f "$cnf" ]; then
        error "${cnf} is missing — MySQL cannot bring up encryption at rest without it."
        error "Re-check out the repository and re-run."
        exit 1
    fi
    chmod 644 "$cnf" 2>/dev/null || true
    mode=$(stat -c '%a' "$cnf")
    if [ "${mode: -1}" -lt 4 ]; then
        error "${cnf} is mode ${mode} — unreadable by the MySQL container (uid 999)."
        error "MySQL would start with encryption at rest silently disabled."
        error "Fix it and re-run:  chmod 644 ${cnf}"
        exit 1
    fi
done
ok "MySQL encryption config readable."

info "Building and starting containers (standalone mode)..."
docker compose --profile standalone \
    -f docker-compose.yml \
    -f docker-compose.onprem.yml \
    up -d --build

info "Waiting for app to become healthy..."
# 300s, not 120: the app healthcheck now only passes once entrypoint.sh has
# finished migrating, so a cold first boot legitimately takes minutes.
timeout=300
elapsed=0
while [ $elapsed -lt $timeout ]; do
    status=$(docker compose --profile standalone \
        -f docker-compose.yml \
        -f docker-compose.onprem.yml \
        ps app --format '{{.Status}}' 2>/dev/null || echo "")
    if echo "$status" | grep -q "(healthy)"; then
        break
    fi
    sleep 5
    elapsed=$((elapsed + 5))
done

if [ $elapsed -ge $timeout ]; then
    error "App container did not become healthy within ${timeout}s."
    error "Check logs: docker compose logs app"
    exit 1
fi

ok "Containers running."

# --- Verify encryption at rest ---
#
# Every way this can break leaves a healthy-looking server behind: the keyring
# component failing to activate, or an unreadable conf.d file taking
# default_table_encryption down with it. Assert the end state rather than
# trusting that the config files did what they say.


# The `|| true` on both is load-bearing under `set -euo pipefail`: a failing
# grep or an unreachable mysql would otherwise abort the script here, skipping
# the diagnostics below — which are the whole point of this check.
info "Verifying encryption at rest..."
DB_ROOT_PASSWORD=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-) || true
ENC_STATUS=$(docker compose --profile standalone \
    -f docker-compose.yml \
    -f docker-compose.onprem.yml \
    exec -T mysql mysql -uroot -p"${DB_ROOT_PASSWORD}" -N -B -e "
        SELECT VARIABLE_VALUE FROM performance_schema.global_variables
         WHERE VARIABLE_NAME='default_table_encryption';
        SELECT STATUS_VALUE FROM performance_schema.keyring_component_status
         WHERE STATUS_KEY='Component_status';" 2>/dev/null | tr '\n' ' ' | tr -s ' ') || true

if [ "$ENC_STATUS" = "ON Active " ]; then
    ok "Encryption at rest active."
else
    error "Encryption at rest is NOT active — got '${ENC_STATUS:-no response}', expected 'ON Active'."
    error "New tables would be written unencrypted."
    error "Check that these are readable by uid 999 and that MySQL loaded them:"
    error "  infra/shared/{mysql.cnf,mysqld.my,component_keyring_file.cnf}"
    error "Logs: docker compose --profile standalone -f docker-compose.yml -f docker-compose.onprem.yml logs mysql"
    error "See the encryption-at-rest section of docs/server-setup.md."
    exit 1
fi

# --- Generate QZ Tray certificate ---

info "Generating QZ Tray certificate..."
QZ_DOMAIN=$(grep '^APP_URL=' .env | sed 's|^APP_URL=https\?://||' | sed 's|:.*||')
openssl genrsa -out storage/app/private/qz-private-key.pem 2048 2>/dev/null
openssl req -x509 -new -sha256 -key storage/app/private/qz-private-key.pem \
    -out public/qz-certificate.pem -days 3650 \
    -subj "/CN=${QZ_DOMAIN}" 2>/dev/null
# The container reads this key through a read-only bind mount as www-data
# (uid 33). Hand ownership to that uid and restrict to 0600 when we have the
# privileges; otherwise fall back to world-readable so the container can still
# read it through the mount.
if chown 33:33 storage/app/private/qz-private-key.pem 2>/dev/null; then
    chmod 600 storage/app/private/qz-private-key.pem
else
    chmod 644 storage/app/private/qz-private-key.pem
    info "QZ private key left world-readable; re-run as root to restrict it to the container user."
fi
ok "QZ Tray certificate generated for ${QZ_DOMAIN}."

# --- Generate SSH keypair for import tunneling ---

info "Generating SSH keypair for import tunneling..."
docker compose --profile standalone \
    -f docker-compose.yml \
    -f docker-compose.onprem.yml \
    exec app php artisan app:generate-ssh-key --force
ok "SSH keypair generated."

# --- Summary ---

APP_URL_DISPLAY=$(grep '^APP_URL=' .env | cut -d= -f2-)

echo ""
echo "==========================================="
echo "  PolyBag installed successfully!"
echo "  URL: ${APP_URL_DISPLAY}"
echo "==========================================="
echo ""
echo "Next steps:"
echo "  1. Create the first admin user:"
echo "     docker compose --profile standalone -f docker-compose.yml -f docker-compose.onprem.yml exec -it app php artisan app:create-user"
echo ""
echo "  2. Open ${APP_URL_DISPLAY} in your browser"
echo ""
