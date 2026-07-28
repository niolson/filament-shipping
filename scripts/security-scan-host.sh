#!/bin/bash
set -euo pipefail

# PolyBag Host OS Vulnerability Scan (Trivy)
#
# Scans the underlying VPS's OS-level packages, kernel, and system services
# (sshd, Caddy, etc.) for known CVEs. This is the gap the container-level
# Grype scans in CI (.github/workflows/ci.yml) don't cover — those only see
# what's baked into the app/nginx images, not the host they run on.
#
# Runs remotely over SSH so no scanning tooling lives on the box permanently
# as extra attack surface beyond the pinned trivy binary itself.
#
# Usage:
#   ./scripts/security-scan-host.sh --ssh root@<server> [--out DIR]
#
# --ssh is REQUIRED (the server is not hardcoded).

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

info()  { echo -e "\033[1;34m[INFO]\033[0m  $*"; }
error() { echo -e "\033[1;31m[ERROR]\033[0m $*" >&2; }
ok()    { echo -e "\033[1;32m[OK]\033[0m    $*"; }

# Pinned to a specific release (not "latest") that had been public for
# several weeks with no follow-up patch at the time it was pinned, and
# checksum-verified against the hash recorded here — not a checksums.txt
# fetched fresh at scan time — so a later compromise of trivy's GitHub
# release page can't silently swap the binary this script installs on a
# root-privileged box. Same rationale as the pinned Semgrep version in
# .github/workflows/security-audit.yml. Bump deliberately, not automatically:
# to update, review the new release's changelog, wait a few weeks, pull the
# new sha256 from that release's `trivy_<ver>_checksums.txt`, and update both
# constants together.
TRIVY_VERSION="0.72.0"
TRIVY_DEB_SHA256="9bf8aba92f524b74f8e83d53b298a7dfc6b4d60aca779217e7817e5433c73eeb"
TRIVY_DEB_URL="https://github.com/aquasecurity/trivy/releases/download/v${TRIVY_VERSION}/trivy_${TRIVY_VERSION}_Linux-64bit.deb"

SSH_HOST=""
OUT_DIR=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --ssh) SSH_HOST="$2"; shift 2 ;;
        --out) OUT_DIR="$2"; shift 2 ;;
        -h|--help) grep '^#' "$0" | grep -v '^#!/' | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) error "Unknown argument: $1"; exit 1 ;;
    esac
done

[[ -n "$SSH_HOST" ]] || { error "--ssh is required (e.g. --ssh root@<server-ip>)"; exit 1; }

info "Resolving hostname via ${SSH_HOST}..."
HOST_LABEL="$(ssh "$SSH_HOST" hostname)"

if [[ -z "$OUT_DIR" ]]; then
    OUT_DIR="$PROJECT_DIR/security-reports/host-${HOST_LABEL}-$(date -u +%Y%m%d-%H%M%S)"
fi
mkdir -p "$OUT_DIR"

info "Installing/verifying pinned Trivy v${TRIVY_VERSION} on ${SSH_HOST}..."
# shellcheck disable=SC2087
ssh "$SSH_HOST" bash -s -- "$TRIVY_VERSION" "$TRIVY_DEB_SHA256" "$TRIVY_DEB_URL" <<'REMOTE'
set -euo pipefail
VERSION="$1"; EXPECTED_SHA="$2"; URL="$3"

INSTALLED="$(dpkg-query -W -f='${Version}' trivy 2>/dev/null || true)"
if [[ "$INSTALLED" != "$VERSION" ]]; then
    TMP_DEB="$(mktemp --suffix=.deb)"
    trap 'rm -f "$TMP_DEB"' EXIT
    curl -fsSL -o "$TMP_DEB" "$URL"
    ACTUAL_SHA="$(sha256sum "$TMP_DEB" | awk '{print $1}')"
    if [[ "$ACTUAL_SHA" != "$EXPECTED_SHA" ]]; then
        echo "Checksum mismatch on trivy .deb: expected $EXPECTED_SHA, got $ACTUAL_SHA — refusing to install" >&2
        exit 1
    fi
    dpkg -i "$TMP_DEB"
fi
trivy --version
REMOTE

info "Scanning host OS packages + kernel (containers are out of scope here — covered separately by Grype in CI)..."
ssh "$SSH_HOST" bash -s <<'REMOTE'
set -euo pipefail
trivy rootfs \
    --pkg-types os \
    --scanners vuln \
    --skip-dirs /proc \
    --skip-dirs /sys \
    --skip-dirs /dev \
    --skip-dirs /run \
    --skip-dirs /mnt \
    --skip-dirs /media \
    --skip-dirs /home \
    --skip-dirs /root/.cache \
    --skip-dirs /tmp \
    --skip-dirs /var/lib/docker \
    --skip-dirs /opt/tenants \
    --format json \
    --output /tmp/trivy-host-report.json \
    /
REMOTE

info "Pulling report back..."
scp -q "$SSH_HOST:/tmp/trivy-host-report.json" "$OUT_DIR/trivy-host-report.json"
ssh "$SSH_HOST" rm -f /tmp/trivy-host-report.json

info "Building summary..."
SCAN_DATE="$(date -u +"%Y-%m-%d %H:%M UTC")"
{
    echo "# Host OS Vulnerability Scan Report"
    echo
    echo "- **Host:** ${HOST_LABEL}"
    echo "- **Scanned:** ${SCAN_DATE}"
    echo "- **Tool:** Trivy v${TRIVY_VERSION} (pinned, checksum-verified)"
    echo "- **Scope:** OS packages, kernel, and system services (sshd, Caddy, etc.) on the underlying VPS — outside Docker containers, which are scanned separately by Grype in CI on every push/PR"
    echo
    echo "## Summary of Findings"
    echo
    echo "| Severity | Count | Fixable |"
    echo "| --- | --- | --- |"
    jq -r '
        [.Results[]?.Vulnerabilities[]?] as $vulns
        | ["CRITICAL","HIGH","MEDIUM","LOW","UNKNOWN"][]
        | . as $sev
        | ($vulns | map(select(.Severity == $sev))) as $matches
        | "| \($sev) | \($matches | length) | \($matches | map(select(.FixedVersion != null and .FixedVersion != "")) | length) |"
    ' "$OUT_DIR/trivy-host-report.json"
    echo
    TOTAL=$(jq '[.Results[]?.Vulnerabilities[]?] | length' "$OUT_DIR/trivy-host-report.json")
    HIGH_CRIT=$(jq '[.Results[]?.Vulnerabilities[]? | select(.Severity == "CRITICAL" or .Severity == "HIGH")] | length' "$OUT_DIR/trivy-host-report.json")
    if [[ "$TOTAL" -eq 0 ]]; then
        echo "No known vulnerabilities found in installed OS packages."
    else
        echo "## Findings Detail (Critical / High)"
        echo
        echo "Medium/Low findings are omitted here for readability (a kernel-package scan"
        echo "routinely surfaces thousands of them); the full list for every severity is in"
        echo "\`trivy-host-report.json\` alongside this file."
        echo
        if [[ "$HIGH_CRIT" -eq 0 ]]; then
            echo "None."
        else
            echo "| Severity | Package | Installed | Fixed In | CVE | Title |"
            echo "| --- | --- | --- | --- | --- | --- |"
            jq -r '
                [.Results[]?.Vulnerabilities[]? | select(.Severity == "CRITICAL" or .Severity == "HIGH")]
                | sort_by(.Severity as $s | ["CRITICAL","HIGH"] | index($s))
                | .[]
                | "| \(.Severity) | \(.PkgName) | \(.InstalledVersion) | \(.FixedVersion // "-") | \(.VulnerabilityID) | \(.Title // "-" | gsub("\\|"; "\\|")) |"
            ' "$OUT_DIR/trivy-host-report.json"
        fi
    fi
    echo
    echo "## Remediation Status"
    echo
    echo "Fixable findings above are remediated via \`apt-get upgrade\` on the host per the standard patching SLA (Critical: 7 days, High: 30 days). Re-run this scan after patching to confirm closure."
} > "$OUT_DIR/host-scan-report.md"

ok "Report written to ${OUT_DIR}/host-scan-report.md"
ok "Raw JSON at ${OUT_DIR}/trivy-host-report.json"
