# ============================================================================
#  Per-hostname Authenticated Origin Pulls — deprovisioning hook.
#
#  Insert into scripts/deprovision-tenant.sh immediately AFTER the Caddy route
#  removal (after `ok "Caddy route removed."`), so ${DOMAIN} is already gone from
#  the Caddyfile when we enumerate the remaining hostnames.
#
#  Same prerequisites as the provision hook: CF_API_TOKEN / CF_ZONE_ID /
#  AOP_CERT_ID sourced from /opt/shared/shared-secrets.env, plus `jq`. Add near
#  the top of the script:
#     [[ -f "${SHARED_DIR}/shared-secrets.env" ]] && source "${SHARED_DIR}/shared-secrets.env"
# ============================================================================

# Only meaningful for Cloudflare-fronted polybag.app tenants. Custom domains
# live in other zones and are handled separately.
if [[ "${MODE}" == "shared" && "${DOMAIN}" == *.polybag.app \
      && -n "${CF_API_TOKEN:-}" && -n "${CF_ZONE_ID:-}" && -n "${AOP_CERT_ID:-}" ]]; then
    info "Disabling Authenticated Origin Pulls for ${DOMAIN}..."

    # Rebuild the FULL desired state and PUT it whole. We send every REMAINING
    # polybag.app hostname as enabled, PLUS the just-removed ${DOMAIN} as
    # explicitly disabled. That is correct whether Cloudflare's PUT replaces or
    # merges: a merge wouldn't drop the removed host on its own, so we disable it
    # by name; a replace gets the complete set anyway.
    mapfile -t AOP_HOSTS < <(
        grep -oE '^[a-z0-9][a-z0-9.-]*\.polybag\.app' "${CADDY_FILE}" 2>/dev/null | sort -u
    )

    AOP_CONFIG=$(
        printf '%s\n' "${AOP_HOSTS[@]}" \
        | jq -R 'select(length > 0)' \
        | jq -s --arg cert "${AOP_CERT_ID}" --arg removed "${DOMAIN}" '
            [ .[] | {hostname: ., cert_id: $cert, enabled: true} ]
            + [ {hostname: $removed, cert_id: $cert, enabled: false} ]'
    )

    AOP_RESP=$(mktemp)
    HTTP_CODE=$(curl -sS -o "${AOP_RESP}" -w '%{http_code}' \
        -X PUT "https://api.cloudflare.com/client/v4/zones/${CF_ZONE_ID}/origin_tls_client_auth/hostnames" \
        -H "Authorization: Bearer ${CF_API_TOKEN}" \
        -H "Content-Type: application/json" \
        -d "{\"config\": ${AOP_CONFIG}}")

    if [[ "${HTTP_CODE}" == "200" ]] && jq -e '.success == true' "${AOP_RESP}" >/dev/null 2>&1; then
        ok "AOP association removed for ${DOMAIN} (${#AOP_HOSTS[@]} hostname(s) still protected)."
    else
        warn "AOP disable returned HTTP ${HTTP_CODE} — the tenant is gone but a stale"
        warn "association for ${DOMAIN} may remain in Cloudflare. Response:"
        jq -r '.errors // .' "${AOP_RESP}" >&2 2>/dev/null || cat "${AOP_RESP}" >&2
    fi
    rm -f "${AOP_RESP}"
else
    info "Skipping AOP teardown (not a shared polybag.app tenant, or AOP env not configured)."
fi
