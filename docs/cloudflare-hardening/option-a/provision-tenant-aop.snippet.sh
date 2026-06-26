# ============================================================================
#  Per-hostname Authenticated Origin Pulls — provisioning hook.
#
#  Insert this block into scripts/provision-tenant.sh immediately AFTER the
#  Caddy reload (after the `ok "Caddy reloaded."` line, ~line 362), so ${DOMAIN}
#  is already present in the Caddyfile when we enumerate hostnames.
#
#  ONE-TIME PREREQUISITES (do these before the first provision):
#    1. Build PKI + upload the *leaf* cert once:
#         POST /zones/{zone}/origin_tls_client_auth/hostnames/certificates
#       Save the returned certificate id.
#    2. Put these in /opt/shared/shared-secrets.env (token scope: Zone → SSL and
#       Certificates → Edit, on the polybag.app zone):
#         CF_API_TOKEN=...        # scoped API token
#         CF_ZONE_ID=...          # polybag.app zone id
#         AOP_CERT_ID=...         # id from step 1
#    3. Ensure `jq` is installed on the provisioning host.
#
#  Requires: the script to have loaded those vars, e.g. near the top:
#     [[ -f /opt/shared/shared-secrets.env ]] && source /opt/shared/shared-secrets.env
# ============================================================================

# Only meaningful for Cloudflare-fronted (shared) tenants on the polybag.app
# zone. Skipped otherwise so standalone/on-prem and custom-domain provisioning
# are unaffected.
if [[ "${MODE}" == "shared" && -n "${CF_API_TOKEN:-}" && -n "${CF_ZONE_ID:-}" && -n "${AOP_CERT_ID:-}" ]]; then
    info "Enabling Authenticated Origin Pulls for ${DOMAIN}..."

    # Rebuild the FULL set of *.polybag.app hostnames from the Caddyfile and PUT
    # it whole. Cloudflare's docs don't state whether this PUT replaces or merges
    # existing associations, so we always send the complete desired state: a new
    # tenant can never silently drop AOP from the others, and a re-run self-heals.
    # (Custom-domain tenants live in other zones and are intentionally excluded.)
    mapfile -t AOP_HOSTS < <(
        grep -oE '^[a-z0-9][a-z0-9.-]*\.polybag\.app' "${CADDY_DIR}/Caddyfile" | sort -u
    )

    if (( ${#AOP_HOSTS[@]} == 0 )); then
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
            error "Tenant ${DOMAIN} is live but NOT AOP-protected. Fix before"
            error "relying on the firewall, or it is reachable via the CF bypass."
        fi
        rm -f "${AOP_RESP}"
    fi
else
    info "Skipping AOP enablement (not shared mode, or AOP env not configured)."
fi
