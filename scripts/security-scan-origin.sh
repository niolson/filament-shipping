#!/bin/bash
set -euo pipefail

# PolyBag Origin DAST Scan (OWASP ZAP)
#
# Runs an OWASP ZAP baseline scan against a tenant's TRUE origin, bypassing
# Cloudflare so the application is scanned rather than the CF edge/WAF.
#
# It does this without ever placing the SSH key in a container:
#   1. Opens a host-side SSH tunnel to the tenant's nginx container.
#   2. Temporarily allows the Docker subnet to reach the tunnel port (ufw).
#   3. Runs a local Caddy TLS terminator (on a private Docker network) that
#      presents the tenant hostname and forwards to the tunnel.
#   4. Runs ZAP on the same network; it resolves the hostname to Caddy.
#   5. Tears EVERYTHING down on exit (tunnel, ufw rule, containers, network).
#
# Reports (HTML + JSON + MD) are written to the output directory.
#
# Usage:
#   ./scripts/security-scan-origin.sh --ssh root@<server-ip> [--host test.polybag.app]
#                                     [--origin-container test-nginx-1]
#                                     [--out DIR] [--full]
#
# --ssh is REQUIRED (the origin server is not hardcoded).
#
# Requirements: docker, ssh access to the server, sudo (for the temp ufw rule).

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# --- Helpers ---

info()  { echo -e "\033[1;34m[INFO]\033[0m  $*"; }
error() { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; }
ok()    { echo -e "\033[1;32m[OK]\033[0m    $*"; }

# --- Defaults (override via flags) ---

TARGET_HOST="test.polybag.app"
SSH_HOST=""                        # REQUIRED: ssh target for the origin server, e.g. root@1.2.3.4
ORIGIN_CONTAINER=""                # auto-derived from TARGET_HOST if empty
TUNNEL_PORT="8080"
DOCKER_NET="zap-scan-net"
PROXY_NAME="zap-scan-tlsproxy"
DOCKER_SUBNETS="172.16.0.0/12"     # Docker private range allowed to reach the tunnel
SCAN_MODE="baseline"               # or "full" (active scan) with --full
OUT_DIR=""

# --- Parse args ---

while [[ $# -gt 0 ]]; do
    case "$1" in
        --host)             TARGET_HOST="$2"; shift 2 ;;
        --ssh)              SSH_HOST="$2"; shift 2 ;;
        --origin-container) ORIGIN_CONTAINER="$2"; shift 2 ;;
        --port)             TUNNEL_PORT="$2"; shift 2 ;;
        --out)              OUT_DIR="$2"; shift 2 ;;
        --full)             SCAN_MODE="full"; shift ;;
        -h|--help)          grep '^#' "$0" | grep -v '^#!/' | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)                  error "Unknown argument: $1"; exit 1 ;;
    esac
done

[[ -n "$SSH_HOST" ]] || { error "--ssh is required (e.g. --ssh root@<server-ip>)"; exit 1; }

# Derive the origin container name from the first hostname label (test.* -> test-nginx-1)
if [[ -z "$ORIGIN_CONTAINER" ]]; then
    ORIGIN_CONTAINER="${TARGET_HOST%%.*}-nginx-1"
fi
if [[ -z "$OUT_DIR" ]]; then
    OUT_DIR="$PROJECT_DIR/security-reports/${TARGET_HOST}-$(date -u +%Y%m%d-%H%M%S)"
fi

# --- Cleanup (always runs) ---

TUNNEL_PID=""
UFW_RULE_ADDED=false
NET_CREATED=false

cleanup() {
    info "Tearing down scan infrastructure..."
    docker rm -f "$PROXY_NAME" >/dev/null 2>&1 || true
    if [[ -n "$TUNNEL_PID" ]] && kill -0 "$TUNNEL_PID" 2>/dev/null; then
        kill "$TUNNEL_PID" 2>/dev/null || true
        ok "SSH tunnel (pid $TUNNEL_PID) stopped"
    fi
    if [[ "$UFW_RULE_ADDED" == true ]]; then
        sudo ufw delete allow from "$DOCKER_SUBNETS" to any port "$TUNNEL_PORT" proto tcp >/dev/null 2>&1 || true
        ok "Temporary ufw rule removed"
    fi
    if [[ "$NET_CREATED" == true ]]; then
        docker network rm "$DOCKER_NET" >/dev/null 2>&1 || true
    fi
    ok "Cleanup complete"
}
trap cleanup EXIT

# --- Pre-flight ---

command -v docker >/dev/null || { error "docker not found"; exit 1; }
mkdir -p "$OUT_DIR"

info "Target host : $TARGET_HOST"
info "SSH server  : $SSH_HOST"
info "Origin ctr  : $ORIGIN_CONTAINER"
info "Scan mode   : $SCAN_MODE"
info "Output dir  : $OUT_DIR"

# --- 1. Resolve origin container IP on the server ---

info "Resolving origin container IP on the server..."
ORIGIN_IP="$(ssh -o ConnectTimeout=10 -o BatchMode=yes "$SSH_HOST" \
    "docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' '$ORIGIN_CONTAINER'" \
    2>/dev/null | awk '{print $1}')"
[[ -n "$ORIGIN_IP" ]] || { error "Could not resolve IP for $ORIGIN_CONTAINER on $SSH_HOST"; exit 1; }
ok "Origin container IP: $ORIGIN_IP"

# --- 2. Docker network ---

docker network rm "$DOCKER_NET" >/dev/null 2>&1 || true
docker network create "$DOCKER_NET" >/dev/null
NET_CREATED=true

# --- 3. Host-side SSH tunnel (key stays on host) ---

if ss -ltn 2>/dev/null | grep -q ":${TUNNEL_PORT}\b"; then
    error "Port $TUNNEL_PORT already in use locally; pass --port to choose another"; exit 1
fi
info "Opening SSH tunnel  *:$TUNNEL_PORT -> $ORIGIN_IP:80 ..."
ssh -o ConnectTimeout=10 -o BatchMode=yes -o ExitOnForwardFailure=yes \
    -o GatewayPorts=yes -o ServerAliveInterval=30 \
    -L "*:${TUNNEL_PORT}:${ORIGIN_IP}:80" -N "$SSH_HOST" &
TUNNEL_PID=$!
sleep 4
kill -0 "$TUNNEL_PID" 2>/dev/null || { error "SSH tunnel failed to start"; exit 1; }
ok "SSH tunnel up (pid $TUNNEL_PID)"

# --- 4. Temporary ufw allowance (ufw INPUT default is DROP) ---

if command -v ufw >/dev/null && sudo ufw status 2>/dev/null | grep -q "Status: active"; then
    sudo ufw allow from "$DOCKER_SUBNETS" to any port "$TUNNEL_PORT" proto tcp \
        comment "TEMP zap-scan ${TARGET_HOST}" >/dev/null
    UFW_RULE_ADDED=true
    ok "Temporary ufw rule added ($DOCKER_SUBNETS -> :$TUNNEL_PORT)"
else
    info "ufw not active; skipping firewall rule"
fi

# --- 5. Caddy TLS terminator presenting the tenant hostname ---

CADDYFILE="$OUT_DIR/Caddyfile"
cat > "$CADDYFILE" <<EOF
{
	admin off
}
${TARGET_HOST}:443 {
	tls internal
	reverse_proxy host.docker.internal:${TUNNEL_PORT} {
		header_up Host ${TARGET_HOST}
		header_up X-Forwarded-Proto https
	}
}
EOF

info "Starting TLS proxy ($PROXY_NAME)..."
docker run -d --name "$PROXY_NAME" \
    --network "$DOCKER_NET" --network-alias "$TARGET_HOST" \
    --add-host host.docker.internal:host-gateway \
    -v "$CADDYFILE":/etc/caddy/Caddyfile:ro \
    caddy:alpine >/dev/null

# --- 6. Wait for the chain to be reachable ---

info "Verifying origin reachability through the chain..."
READY=false
for _ in $(seq 1 15); do
    code="$(docker run --rm --network "$DOCKER_NET" curlimages/curl:latest \
        -sk -o /dev/null -w '%{http_code}' "https://${TARGET_HOST}/" --max-time 10 2>/dev/null || true)"
    if [[ "$code" =~ ^(200|302)$ ]]; then READY=true; break; fi
    sleep 2
done
[[ "$READY" == true ]] || { error "Origin not reachable through the chain (last code: ${code:-none})"; docker logs "$PROXY_NAME" 2>&1 | tail -5; exit 1; }
ok "Chain verified (HTTP $code)"

# --- 7. Run ZAP ---

if [[ "$SCAN_MODE" == "full" ]]; then
    ZAP_CMD="zap-full-scan.py"
    info "Running ZAP FULL (active) scan — sends attack payloads. Ensure target data is disposable."
else
    ZAP_CMD="zap-baseline.py"
    info "Running ZAP baseline (passive) scan..."
fi

# -I => do not fail the script on warnings; reports still written.
docker run --rm --network "$DOCKER_NET" -v "$OUT_DIR":/zap/wrk:rw -t zaproxy/zap-stable \
    "$ZAP_CMD" -t "https://${TARGET_HOST}" \
    -r zap-report.html -J zap-report.json -w zap-report.md -I \
    | tee "$OUT_DIR/zap-run.log" || true

ok "Scan complete. Reports in: $OUT_DIR"
echo
grep -E 'FAIL-NEW|WARN-NEW|PASS:' "$OUT_DIR/zap-run.log" | tail -1 || true
