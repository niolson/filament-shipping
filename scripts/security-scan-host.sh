#!/bin/bash
set -euo pipefail

# PolyBag Host OS Vulnerability Scan (Trivy)
#
# Scans the underlying VPS's OS-level packages, kernel, and system services
# (sshd, Caddy, etc.) for known CVEs. This is the gap the container-level
# Grype scans in CI (.github/workflows/ci.yml) don't cover — those only see
# what's baked into the app/nginx images, not the host they run on.
#
# Two modes, one definition of what gets scanned:
#
#   --ssh <target>  Drive the scan remotely from a workstation. Nothing but the
#                   pinned trivy binary is left on the box; the report is pulled
#                   back into security-reports/ for handing to a reviewer.
#
#   --local         Run against the machine the script is on. This is the mode
#                   the scheduled scan on the server uses (see
#                   /etc/cron.d/trivy-host-scan and docs/server-setup.md).
#
# Usage:
#   ./scripts/security-scan-host.sh --ssh root@<server> [--out DIR]
#   ./scripts/security-scan-host.sh --local [--out DIR] [--email ADDR] [--keep N]
#
# Exactly one of --ssh or --local is REQUIRED (the server is not hardcoded).
#
# Options:
#   --out DIR      Write the report here instead of a dated directory.
#   --out-parent DIR
#                  Write a dated report directory under DIR. Prefer this over
#                  --out from cron: it keeps the timestamp out of the crontab,
#                  where a bare % is a newline and would truncate the command.
#   --email ADDR   Mail the report via Resend when the scan finishes, and mail a
#                  failure notice if it dies partway. Comma-separate for several
#                  recipients. Needs RESEND_API_KEY in the environment or in
#                  /opt/shared/shared-secrets.env (where the app already keeps
#                  it). Override the sender with SECURITY_SCAN_EMAIL_FROM.
#   --images       Also scan the image behind every running container. Grype in
#                  CI only scans the app image it builds itself, so third-party
#                  images (database, cache, TLS terminator, PDF renderer) and
#                  the app image as actually rebuilt on the server are otherwise
#                  never checked by anything.
#   --keep N       After a successful scan, keep only the N most recent report
#                  directories for this host and delete older ones. Default 0
#                  (keep everything); the scheduled scan passes a real value so
#                  monthly reports don't accumulate on the VPS disk.

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

MODE=""
SSH_HOST=""
OUT_DIR=""
OUT_PARENT=""
EMAIL_TO=""
KEEP=0
SCAN_IMAGES=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --ssh) MODE="remote"; SSH_HOST="$2"; shift 2 ;;
        --local) MODE="local"; shift ;;
        --images) SCAN_IMAGES=1; shift ;;
        --out) OUT_DIR="$2"; shift 2 ;;
        --out-parent) OUT_PARENT="$2"; shift 2 ;;
        --email) EMAIL_TO="$2"; shift 2 ;;
        --keep) KEEP="$2"; shift 2 ;;
        -h|--help) grep '^#' "$0" | grep -v '^#!/' | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) error "Unknown argument: $1"; exit 1 ;;
    esac
done

if [[ -z "$MODE" ]]; then
    error "One of --ssh <target> or --local is required"
    exit 1
fi
if [[ "$MODE" == "remote" && -z "$SSH_HOST" ]]; then
    error "--ssh needs a target (e.g. --ssh root@<server-ip>)"
    exit 1
fi
if [[ ! "$KEEP" =~ ^[0-9]+$ ]]; then
    error "--keep needs a non-negative integer, got: ${KEEP}"
    exit 1
fi

# Runs a heredoc script either on the remote host or right here, so the scan
# itself is written down exactly once regardless of how it was invoked.
run_on_host() {
    if [[ "$MODE" == "remote" ]]; then
        ssh "$SSH_HOST" bash -s -- "$@"
    else
        bash -s -- "$@"
    fi
}

# --- Email (Resend) ---

EMAIL_FROM="${SECURITY_SCAN_EMAIL_FROM:-noreply@updates.polybag.app}"
SHARED_SECRETS="${SHARED_SECRETS_FILE:-/opt/shared/shared-secrets.env}"
RESEND_KEY="${RESEND_API_KEY:-}"
if [[ -z "$RESEND_KEY" && -n "$EMAIL_TO" && -f "$SHARED_SECRETS" ]]; then
    RESEND_KEY="$(grep -hE '^RESEND_API_KEY=' "$SHARED_SECRETS" 2>/dev/null | head -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/')"
fi

EMAIL_SENT=0

# Takes the body as a FILE, not a string. A report carrying a few thousand
# findings runs to hundreds of kilobytes, which overflows the argument list if
# passed via jq --arg — and the failure is quiet enough to send an empty mail.
# --rawfile and `curl -d @file` keep the body off the command line entirely.
send_email() {
    local subject="$1" body_file="$2" payload_file status
    [[ -n "$EMAIL_TO" ]] || return 0

    if [[ -z "$RESEND_KEY" ]]; then
        error "No RESEND_API_KEY in the environment or ${SHARED_SECRETS}; cannot send: ${subject}"
        return 1
    fi

    payload_file="$(mktemp)"
    if ! jq -n \
        --arg from "$EMAIL_FROM" \
        --arg to "$EMAIL_TO" \
        --arg subject "$subject" \
        --rawfile text "$body_file" \
        '{from: $from, to: ($to | split(",") | map(gsub("^\\s+|\\s+$"; ""))), subject: $subject, text: $text}' \
        > "$payload_file"; then
        error "Could not build the email payload for: ${subject}"
        rm -f "$payload_file"
        return 1
    fi

    status=0
    curl -fsS -m 60 -X POST "https://api.resend.com/emails" \
        -H "Authorization: Bearer ${RESEND_KEY}" \
        -H "Content-Type: application/json" \
        -d @"$payload_file" >/dev/null || status=$?
    rm -f "$payload_file"

    if [[ "$status" -eq 0 ]]; then
        EMAIL_SENT=1
        ok "Result email sent to ${EMAIL_TO}"
        return 0
    fi

    error "Failed to send result email to ${EMAIL_TO} (curl exit ${status})"
    return 1
}

# A scan that dies partway (trivy crash, no disk, DB download failure) must
# still produce mail — otherwise a broken scheduled scan is indistinguishable
# from a clean one, which is exactly how the nightly backup broke silently once.
on_exit() {
    local code=$? failure_body
    if [[ "$code" -ne 0 && -n "$EMAIL_TO" && "$EMAIL_SENT" -eq 0 ]]; then
        failure_body="$(mktemp)"
        cat > "$failure_body" <<FAILURE
The scheduled Trivy host scan exited with status ${code} before it could produce a report.

Host:  ${HOST_LABEL:-unknown}
Mode:  ${MODE}
When:  $(date -u +"%Y-%m-%d %H:%M UTC")

No vulnerability report was written, so the host's current CVE status is unknown
until this is re-run. Check the scan log on the server:

    /var/log/polybag-security-scan.log

then re-run by hand with:

    /opt/tenants/test/scripts/security-scan-host.sh --local --images --out-parent /var/log/trivy-host-scan
FAILURE
        send_email \
            "[PolyBag] Host vulnerability scan FAILED on ${HOST_LABEL:-unknown host}" \
            "$failure_body" || true
        rm -f "$failure_body"
    fi
    return "$code"
}
trap on_exit EXIT

# --- Resolve host + output location ---

if [[ "$MODE" == "remote" ]]; then
    info "Resolving hostname via ${SSH_HOST}..."
    HOST_LABEL="$(ssh "$SSH_HOST" hostname)"
else
    HOST_LABEL="$(hostname)"
fi

if [[ -z "$OUT_DIR" ]]; then
    OUT_DIR="${OUT_PARENT:-$PROJECT_DIR/security-reports}/host-${HOST_LABEL}-$(date -u +%Y%m%d-%H%M%S)"
fi
mkdir -p "$OUT_DIR"
REPORT_JSON="$OUT_DIR/trivy-host-report.json"

info "Installing/verifying pinned Trivy v${TRIVY_VERSION} on ${HOST_LABEL}..."
run_on_host "$TRIVY_VERSION" "$TRIVY_DEB_SHA256" "$TRIVY_DEB_URL" <<'REMOTE'
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

# In remote mode the scan writes to the far side's /tmp and is copied back;
# locally it can write straight into the output directory.
if [[ "$MODE" == "remote" ]]; then
    SCAN_OUTPUT="/tmp/trivy-host-report.json"
else
    SCAN_OUTPUT="$REPORT_JSON"
fi

info "Scanning host OS packages + kernel (containers are out of scope here — covered separately by Grype in CI)..."
run_on_host "$SCAN_OUTPUT" <<'REMOTE'
set -euo pipefail
SCAN_OUTPUT="$1"
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
    --output "$SCAN_OUTPUT" \
    /
REMOTE

if [[ "$MODE" == "remote" ]]; then
    info "Pulling report back..."
    scp -q "$SSH_HOST:$SCAN_OUTPUT" "$REPORT_JSON"
    ssh "$SSH_HOST" rm -f "$SCAN_OUTPUT"
fi

info "Building summary..."
SCAN_DATE="$(date -u +"%Y-%m-%d %H:%M UTC")"
TOTAL=$(jq '[.Results[]?.Vulnerabilities[]?] | length' "$REPORT_JSON")
CRITICAL=$(jq '[.Results[]?.Vulnerabilities[]? | select(.Severity == "CRITICAL")] | length' "$REPORT_JSON")
HIGH=$(jq '[.Results[]?.Vulnerabilities[]? | select(.Severity == "HIGH")] | length' "$REPORT_JSON")
HIGH_CRIT=$(( CRITICAL + HIGH ))
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
    ' "$REPORT_JSON"
    echo
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
            ' "$REPORT_JSON"
        fi
    fi
    echo
    echo "## Remediation Status"
    echo
    echo "Fixable findings above are remediated via \`apt-get upgrade\` on the host per the standard patching SLA (Critical: 7 days, High: 30 days). Re-run this scan after patching to confirm closure."
} > "$OUT_DIR/host-scan-report.md"

# --- Container images ---
#
# Discovered from what is actually running rather than from a hand-kept list,
# so a service added later is covered without anyone remembering to edit this.
# Deduplicated by image ID: the four app containers of a tenant share one image
# and there is no point scanning it four times.
IMAGES_JSON="$OUT_DIR/trivy-images-report.json"
IMAGE_FIXABLE=0
if [[ "$SCAN_IMAGES" -eq 1 ]]; then
    info "Scanning images of running containers..."
    run_on_host <<'REMOTE' > "$IMAGES_JSON"
set -euo pipefail
for container in $(docker ps --format '{{.Names}}'); do
    printf '%s|%s\n' \
        "$(docker inspect -f '{{.Image}}' "$container")" \
        "$(docker inspect -f '{{.Config.Image}}' "$container")"
done | sort -u -t'|' -k1,1 | while IFS='|' read -r image_id image_ref; do
    trivy image --scanners vuln --skip-version-check --quiet --format json "$image_id" 2>/dev/null \
        | jq -c --arg image "$image_ref" --arg id "$image_id" \
            '{image: $image, id: $id, results: (.Results // [])}'
done | jq -s '.'
REMOTE

    IMAGE_FIXABLE=$(jq '[.[].results[]?.Vulnerabilities[]? | select((.Severity == "CRITICAL" or .Severity == "HIGH") and .FixedVersion != null and .FixedVersion != "")] | length' "$IMAGES_JSON")

    {
        echo
        echo "## Container Images"
        echo
        echo "Images behind every running container, deduplicated. Grype in CI scans"
        echo "only the app image it builds itself, so everything else here — and the app"
        echo "image as rebuilt on this host — is covered by nothing else."
        echo
        echo "Sorted by fixable critical/high, because that is the column worth acting on:"
        echo "a fixable finding clears by pulling a newer image, an unfixable one does not."
        echo
        echo "| Image | Fixable Crit/High | Critical | High |"
        echo "| --- | --- | --- | --- |"
        jq -r '
            def short: sub("@sha256:(?<h>[0-9a-f]{12})[0-9a-f]*$"; "@\(.h)");
            map({
                image: (.image | short),
                critical: ([.results[]?.Vulnerabilities[]? | select(.Severity == "CRITICAL")] | length),
                high: ([.results[]?.Vulnerabilities[]? | select(.Severity == "HIGH")] | length),
                fixable: ([.results[]?.Vulnerabilities[]? | select((.Severity == "CRITICAL" or .Severity == "HIGH") and .FixedVersion != null and .FixedVersion != "")] | length),
            })
            | sort_by(-.fixable, -.critical)
            | .[]
            | "| \(.image) | \(.fixable) | \(.critical) | \(.high) |"
        ' "$IMAGES_JSON"
        echo
        if [[ "$IMAGE_FIXABLE" -eq 0 ]]; then
            echo "No fixable critical/high findings in any running image."
        else
            echo "### Fixable Critical / High"
            echo
            echo "Each of these clears by pulling a current version of the image and"
            echo "recreating the container. Unfixable findings are omitted — see"
            echo "\`trivy-images-report.json.gz\` for the complete set at every severity."
            echo
            echo "| Image | Severity | Package | Installed | Fixed In | CVE |"
            echo "| --- | --- | --- | --- | --- | --- |"
            jq -r '
                def short: sub("@sha256:(?<h>[0-9a-f]{12})[0-9a-f]*$"; "@\(.h)");
                [ .[] | (.image | short) as $img | .results[]?.Vulnerabilities[]?
                  | select((.Severity == "CRITICAL" or .Severity == "HIGH") and .FixedVersion != null and .FixedVersion != "")
                  | {image: $img, severity: .Severity, pkg: .PkgName, installed: .InstalledVersion, fixed: .FixedVersion, cve: .VulnerabilityID} ]
                | sort_by(.image, (.severity | if . == "CRITICAL" then 0 else 1 end), .pkg)
                | .[]
                | "| \(.image) | \(.severity) | \(.pkg) | \(.installed) | \(.fixed) | \(.cve) |"
            ' "$IMAGES_JSON"
        fi
    } >> "$OUT_DIR/host-scan-report.md"

    # 14MB raw for a single large image, ~1MB gzipped. With a year of monthly
    # reports retained that difference decides whether this fits on the VPS.
    gzip -f "$IMAGES_JSON"
    ok "Image report at ${IMAGES_JSON}.gz"
fi

ok "Report written to ${OUT_DIR}/host-scan-report.md"
ok "Raw JSON at ${OUT_DIR}/trivy-host-report.json"

# --- Retention ---
#
# Keeps the N newest report directories for this host. Names end in a UTC
# timestamp, so a reverse lexical sort is also a reverse chronological one.
if [[ "$KEEP" -gt 0 ]]; then
    PARENT_DIR="$(dirname "$OUT_DIR")"
    while IFS= read -r stale; do
        [[ -n "$stale" ]] || continue
        rm -rf -- "${PARENT_DIR:?}/${stale}"
        info "Pruned old report ${stale}"
    done < <(find "$PARENT_DIR" -maxdepth 1 -mindepth 1 -type d -name "host-${HOST_LABEL}-*" -printf '%f\n' 2>/dev/null | sort -r | tail -n +$((KEEP + 1)))
fi

# --- Result email ---
#
# Sent on every run, not only when something is wrong. A scan that quietly stops
# running looks exactly like a clean scan if mail only goes out on findings, so
# the monthly message doubles as proof the job still fires.
if [[ -n "$EMAIL_TO" ]]; then
    if [[ "$HIGH_CRIT" -gt 0 ]]; then
        SUBJECT="[PolyBag] Host scan: ${CRITICAL} critical / ${HIGH} high on ${HOST_LABEL}"
    elif [[ "$TOTAL" -gt 0 ]]; then
        SUBJECT="[PolyBag] Host scan clean (no critical/high) on ${HOST_LABEL}"
    else
        SUBJECT="[PolyBag] Host scan clean (no findings) on ${HOST_LABEL}"
    fi

    # Host findings are routinely unfixable kernel CVEs, so the subject would
    # look identical month to month. Surfacing the fixable image count is what
    # tells you an actual action is waiting without opening the mail.
    if [[ "$SCAN_IMAGES" -eq 1 && "$IMAGE_FIXABLE" -gt 0 ]]; then
        SUBJECT="${SUBJECT} · images: ${IMAGE_FIXABLE} fixable"
    fi

    # A report listing every fixable finding across a dozen images runs to
    # thousands of lines — unreadable as mail, and Resend rejects very large
    # bodies outright. Send the summary tables and enough detail to act on,
    # and point at the full report rather than inlining it.
    EMAIL_BODY="$(mktemp)"
    MAX_EMAIL_LINES=250
    head -n "$MAX_EMAIL_LINES" "$OUT_DIR/host-scan-report.md" > "$EMAIL_BODY"
    if [[ "$(wc -l < "$OUT_DIR/host-scan-report.md")" -gt "$MAX_EMAIL_LINES" ]]; then
        printf '\n[Truncated at %s lines for email.]\n' "$MAX_EMAIL_LINES" >> "$EMAIL_BODY"
    fi
    cat >> "$EMAIL_BODY" <<FOOTER

---
Full report on the server:
    ${OUT_DIR}/host-scan-report.md
    ${REPORT_JSON}
FOOTER
    if [[ "$SCAN_IMAGES" -eq 1 ]]; then
        echo "    ${IMAGES_JSON}.gz" >> "$EMAIL_BODY"
    fi

    send_email "$SUBJECT" "$EMAIL_BODY"
    rm -f "$EMAIL_BODY"
fi
