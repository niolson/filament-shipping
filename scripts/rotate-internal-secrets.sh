#!/bin/bash
set -euo pipefail

# PolyBag Internal Secret Rotation
# Rotates server-managed secrets. By default rotates everything; use --only/--skip
# to rotate a subset (e.g. rotate the infra credentials often, APP_KEY annually).
#
# Components (names for --only / --skip):
#   redis        REDIS_PASSWORD        (/opt/shared/.env + shared-secrets.env + live Redis)
#   mysql-root   MYSQL_ROOT_PASSWORD   (/opt/shared/.env + live MySQL)   [no restart needed]
#   oauth        OAUTH_BROKER_SECRET   (shared-secrets.env + polybag-connect .env, if present)
#   db-password  DB_PASSWORD           (each shared-mode tenant's .env + live MySQL)
#   app-key      APP_KEY               (each shared tenant's .env; old key kept in
#                                        APP_PREVIOUS_KEYS, then DB secrets re-encrypted)
#
# Any component except mysql-root requires restarting tenant containers to take
# effect. app-key is the most disruptive (rotates the Livewire endpoint hash and
# invalidates in-flight sessions once the previous key is dropped), so a common
# pattern is: rotate infra credentials quarterly with --no-app-key, and run a full
# rotation (including app-key) annually.
#
# Standalone tenants are skipped — their per-tenant MySQL and Redis require
# separate handling (rotate their APP_KEY with the same approach individually).
#
# Does NOT rotate: BACKUP_ENCRYPTION_KEY (see rotate-backup-key.sh), or any
# external API credentials (Google, USPS, carriers).
#
# Usage:
#   ./scripts/rotate-internal-secrets.sh                     # rotate everything (prompts)
#   ./scripts/rotate-internal-secrets.sh --no-app-key        # everything except APP_KEY
#   ./scripts/rotate-internal-secrets.sh --only=redis,mysql-root
#   ./scripts/rotate-internal-secrets.sh --skip=oauth
#   ./scripts/rotate-internal-secrets.sh --yes               # skip confirmation
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

# Like set_env, but appends the key if it is not already present.
set_or_add_env() {
    local file="$1" key="$2" value="$3"
    if grep -qE "^${key}=" "$file"; then
        set_env "$file" "$key" "$value"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

# Laravel APP_KEY for the AES-256-CBC cipher: 32 random bytes, base64-encoded.
generate_app_key() { echo "base64:$(openssl rand -base64 32)"; }

# --- Component selection ---

ALL_COMPONENTS=(redis mysql-root oauth db-password app-key)
declare -A SELECTED
for c in "${ALL_COMPONENTS[@]}"; do SELECTED["$c"]=1; done

want() { [ "${SELECTED[$1]:-0}" = "1" ]; }

valid_component() {
    local c
    for c in "${ALL_COMPONENTS[@]}"; do [ "$c" = "$1" ] && return 0; done
    return 1
}

# --- Parse arguments ---

DRY_RUN=false
YES=false
ONLY_SET=false
ONLY_LIST=()
SKIP_LIST=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)    DRY_RUN=true; shift ;;
        --yes)        YES=true; shift ;;
        --no-app-key) SKIP_LIST+=("app-key"); shift ;;
        --only=*)     ONLY_SET=true; IFS=',' read -ra ONLY_LIST <<< "${1#*=}"; shift ;;
        --skip=*)     IFS=',' read -ra _skip <<< "${1#*=}"; SKIP_LIST+=("${_skip[@]}"); shift ;;
        *) error "Unknown option: $1"; exit 1 ;;
    esac
done

for c in ${ONLY_LIST[@]+"${ONLY_LIST[@]}"} ${SKIP_LIST[@]+"${SKIP_LIST[@]}"}; do
    valid_component "$c" || { error "Unknown component '$c'. Valid: ${ALL_COMPONENTS[*]}"; exit 1; }
done

if $ONLY_SET; then
    for c in "${ALL_COMPONENTS[@]}"; do SELECTED["$c"]=0; done
    for c in ${ONLY_LIST[@]+"${ONLY_LIST[@]}"}; do SELECTED["$c"]=1; done
fi
for c in ${SKIP_LIST[@]+"${SKIP_LIST[@]}"}; do SELECTED["$c"]=0; done

ANY_SELECTED=false
for c in "${ALL_COMPONENTS[@]}"; do want "$c" && ANY_SELECTED=true; done
$ANY_SELECTED || { error "No components selected to rotate."; exit 1; }

# Any component except mysql-root is injected into tenant containers via env, so
# rotating it requires a restart to take effect.
NEEDS_RESTART=false
if want redis || want oauth || want db-password || want app-key; then
    NEEDS_RESTART=true
fi

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
if want redis && ! docker inspect shared-redis --format '{{.State.Running}}' 2>/dev/null | grep -q true; then
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

if want redis; then
    [ -n "$OLD_REDIS" ] || { error "REDIS_PASSWORD not set in ${SHARED_DIR}/shared-secrets.env"; exit 1; }
fi
# Root password is needed to authenticate the MySQL admin changes for either
# mysql-root or db-password rotation.
if want mysql-root || want db-password; then
    [ -n "$OLD_MYSQL_ROOT" ] || { error "MYSQL_ROOT_PASSWORD not set in ${SHARED_DIR}/.env"; exit 1; }
fi

# --- Generate new values ---

if want redis;      then NEW_REDIS=$(generate_password); fi
if want mysql-root; then NEW_MYSQL_ROOT=$(generate_password); fi
if want oauth;      then NEW_OAUTH=$(generate_password); fi

# Password used to run MySQL admin statements (Step 3 runs after Step 2, so use
# the new root password if it was rotated, otherwise the current one).
ADMIN_MYSQL_PASS="$OLD_MYSQL_ROOT"
if want mysql-root; then ADMIN_MYSQL_PASS="$NEW_MYSQL_ROOT"; fi

# Build list of shared-mode tenants and per-tenant new values.
SHARED_TENANTS=()
declare -A TENANT_DB_USER
declare -A TENANT_NEW_PASS
declare -A TENANT_OLD_APPKEY
declare -A TENANT_NEW_APPKEY

for tenant_dir in "${TENANTS_DIR}"/*/; do
    [ -f "${tenant_dir}.env" ] || continue
    [ -f "${tenant_dir}docker-compose.yml" ] || continue
    tenant=$(basename "$tenant_dir")
    db_host=$(grep '^DB_HOST=' "${tenant_dir}.env" | cut -d= -f2- || true)
    if [ "$db_host" = "shared-mysql" ]; then
        SHARED_TENANTS+=("$tenant")
        if want db-password; then
            TENANT_DB_USER[$tenant]=$(grep '^DB_USERNAME=' "${tenant_dir}.env" | cut -d= -f2-)
            TENANT_NEW_PASS[$tenant]=$(generate_password)
        fi
        if want app-key; then
            TENANT_OLD_APPKEY[$tenant]=$(grep '^APP_KEY=' "${tenant_dir}.env" | cut -d= -f2- || true)
            TENANT_NEW_APPKEY[$tenant]=$(generate_app_key)
        fi
    else
        warn "Skipping standalone tenant '${tenant}' — rotate its secrets separately."
    fi
done

# --- Summary ---

echo ""
echo "Will rotate:"
want redis      && echo "  REDIS_PASSWORD       → ${SHARED_DIR}/shared-secrets.env + ${SHARED_DIR}/.env + live Redis"
want mysql-root && echo "  MYSQL_ROOT_PASSWORD  → ${SHARED_DIR}/.env + live MySQL"
if want oauth; then
    if $HAS_CONNECT; then
        echo "  OAUTH_BROKER_SECRET  → ${SHARED_DIR}/shared-secrets.env + ${CONNECT_DIR}/.env"
    else
        echo "  OAUTH_BROKER_SECRET  → ${SHARED_DIR}/shared-secrets.env only"
        warn "polybag-connect not found at ${CONNECT_DIR} — update its SHARED_TENANT_SECRET manually."
    fi
fi
if [ ${#SHARED_TENANTS[@]} -gt 0 ]; then
    want db-password && echo "  DB_PASSWORD          → ${#SHARED_TENANTS[@]} shared tenant(s): ${SHARED_TENANTS[*]}"
    want app-key     && echo "  APP_KEY              → ${#SHARED_TENANTS[@]} shared tenant(s) (old key kept in APP_PREVIOUS_KEYS, then secrets re-encrypted)"
fi
$NEEDS_RESTART && echo "  (tenant containers will be restarted)"
echo ""

if $DRY_RUN; then
    ok "Dry run complete — no changes made."
    exit 0
fi

# --- Confirmation ---

if ! $YES; then
    PROMPT="Proceed with rotation?"
    $NEEDS_RESTART && PROMPT="${PROMPT} This will briefly restart tenant containers."
    read -r -p "${PROMPT} [y/N] " CONFIRM
    [[ "$CONFIRM" =~ ^[Yy]$ ]] || { info "Aborted."; exit 0; }
fi

# --- Step 1: Update all files first ---

step "Updating secret files"

want mysql-root && set_env "${SHARED_DIR}/.env" "MYSQL_ROOT_PASSWORD" "$NEW_MYSQL_ROOT"
if want redis; then
    set_env "${SHARED_DIR}/.env" "REDIS_PASSWORD" "$NEW_REDIS"
    set_env "${SHARED_DIR}/shared-secrets.env" "REDIS_PASSWORD" "$NEW_REDIS"
fi
if want oauth; then
    set_env "${SHARED_DIR}/shared-secrets.env" "OAUTH_BROKER_SECRET" "$NEW_OAUTH"
    $HAS_CONNECT && set_env "${CONNECT_DIR}/.env" "SHARED_TENANT_SECRET" "$NEW_OAUTH"
fi

if [ ${#SHARED_TENANTS[@]} -gt 0 ]; then
    for tenant in "${SHARED_TENANTS[@]}"; do
        want db-password && set_env "${TENANTS_DIR}/${tenant}/.env" "DB_PASSWORD" "${TENANT_NEW_PASS[$tenant]}"
        if want app-key; then
            # Keep the outgoing APP_KEY as the (single) previous key so existing
            # encrypted data stays readable until it is re-encrypted in Step 6.
            set_or_add_env "${TENANTS_DIR}/${tenant}/.env" "APP_PREVIOUS_KEYS" "${TENANT_OLD_APPKEY[$tenant]}"
            set_env "${TENANTS_DIR}/${tenant}/.env" "APP_KEY" "${TENANT_NEW_APPKEY[$tenant]}"
        fi
    done
fi

ok "Files updated."

# --- Step 2: Rotate MySQL root password ---

if want mysql-root; then
    step "Rotating MySQL root password"
    docker exec shared-mysql mysql -uroot -p"${OLD_MYSQL_ROOT}" -e "
        ALTER USER 'root'@'localhost' IDENTIFIED BY '${NEW_MYSQL_ROOT}';
        ALTER USER IF EXISTS 'root'@'%' IDENTIFIED BY '${NEW_MYSQL_ROOT}';
        FLUSH PRIVILEGES;
    "
    ok "MySQL root password rotated."
fi

# --- Step 3: Rotate tenant DB passwords ---

if want db-password && [ ${#SHARED_TENANTS[@]} -gt 0 ]; then
    step "Rotating tenant DB passwords"
    for tenant in "${SHARED_TENANTS[@]}"; do
        docker exec shared-mysql mysql -uroot -p"${ADMIN_MYSQL_PASS}" -e "
            ALTER USER '${TENANT_DB_USER[$tenant]}'@'%' IDENTIFIED BY '${TENANT_NEW_PASS[$tenant]}';
            FLUSH PRIVILEGES;
        "
        ok "  ${tenant}"
    done
fi

# --- Step 4: Restart polybag-connect (its SHARED_TENANT_SECRET changed) ---

if want oauth && $HAS_CONNECT; then
    step "Restarting polybag-connect"
    (cd "$CONNECT_DIR" && docker compose up -d --force-recreate)
    ok "polybag-connect restarted."
fi

# --- Step 5: Rotate Redis password, then restart tenant containers ---
#
# Redis CONFIG SET takes effect instantly — running containers holding the old
# password fail to connect until restarted. Any rotated secret that lives in a
# tenant's env also only takes effect on restart, so we restart once for all.

if want redis; then
    step "Rotating Redis password"
    docker exec shared-redis redis-cli -a "${OLD_REDIS}" --no-auth-warning \
        CONFIG SET requirepass "${NEW_REDIS}"
    ok "Redis requirepass updated."
fi

if $NEEDS_RESTART && [ ${#SHARED_TENANTS[@]} -gt 0 ]; then
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
        error "${FAILED} tenant(s) failed to restart — connections may be broken."
        error "Run: cd /opt/tenants/<tenant> && docker compose up -d --force-recreate app queue scheduler"
        exit 1
    fi

    ok "All tenant containers restarted."
fi

# --- Step 6: Re-encrypt tenant secrets with the new APP_KEY ---
#
# The containers are now running on the new APP_KEY with the old one available
# via APP_PREVIOUS_KEYS, so encrypted DB columns can be re-encrypted forward.
# This must run before the previous key is ever removed.

REENCRYPT_FAILED=0
if want app-key && [ ${#SHARED_TENANTS[@]} -gt 0 ]; then
    step "Re-encrypting tenant secrets with the new APP_KEY"
    for tenant in "${SHARED_TENANTS[@]}"; do
        cd "${TENANTS_DIR}/${tenant}"

        # Wait for the app container to report healthy before exec'ing into it.
        elapsed=0
        while [ "$elapsed" -lt 120 ]; do
            docker compose ps app --format '{{.Status}}' 2>/dev/null | grep -q "(healthy)" && break
            sleep 3
            elapsed=$((elapsed + 3))
        done

        if docker compose exec -T app php artisan app:reencrypt-secrets 2>&1 | sed "s/^/  [${tenant}] /"; then
            ok "  ${tenant}"
        else
            error "  ${tenant}: re-encryption failed — leave APP_PREVIOUS_KEYS in place until resolved."
            REENCRYPT_FAILED=$((REENCRYPT_FAILED + 1))
        fi
    done
fi

# --- Done ---

echo ""
echo "==========================================="
echo "  Rotation complete"
want redis      && echo "  REDIS_PASSWORD        ✓"
want mysql-root && echo "  MYSQL_ROOT_PASSWORD   ✓"
want oauth      && echo "  OAUTH_BROKER_SECRET   ✓"
if [ ${#SHARED_TENANTS[@]} -gt 0 ]; then
    want db-password && echo "  DB_PASSWORD           ✓ (${#SHARED_TENANTS[@]} tenant(s))"
    if want app-key; then
        if [ "$REENCRYPT_FAILED" -eq 0 ]; then
            echo "  APP_KEY               ✓ (${#SHARED_TENANTS[@]} tenant(s), secrets re-encrypted)"
        else
            echo "  APP_KEY               ⚠ rotated, but ${REENCRYPT_FAILED} tenant(s) failed re-encryption"
        fi
    fi
fi
echo "==========================================="
echo ""
if want app-key; then
    echo "APP_PREVIOUS_KEYS still holds the old APP_KEY for each tenant so in-flight"
    echo "sessions keep working. It can be cleared on the next rotation, or sooner once"
    echo "SESSION_LIFETIME has elapsed and re-encryption succeeded."
    if [ "$REENCRYPT_FAILED" -gt 0 ]; then
        echo ""
        error "Re-encryption failed for ${REENCRYPT_FAILED} tenant(s). Do NOT remove APP_PREVIOUS_KEYS"
        error "for those tenants until 'php artisan app:reencrypt-secrets' succeeds."
        exit 1
    fi
fi
echo ""
