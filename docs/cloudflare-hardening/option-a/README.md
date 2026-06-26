# Option A — Cloudflare proxy hardening runbook

Goal: orange-cloud `*.polybag.app`, with the origin reachable on :443 **only** from
Cloudflare AND only when Cloudflare presents our Authenticated-Origin-Pulls cert.

> **Critical ordering.** Caddy's AOP *enforcement* (`client_auth require_and_verify`)
> must go live **last** — only after Cloudflare is already presenting the client
> cert. Reload Caddy with enforcement on while the proxy is off (or before AOP is
> enabled) and Caddy rejects everything → instant outage for all tenants. Likewise,
> wiring the provisioning hooks only covers *future* tenants, so existing tenants
> need a one-time bulk enable (step 5). Do this in a low-traffic window and canary
> one tenant (step 4) before going wide.

The firewall lockdown (step 9) is the other lock-yourself-out step, so it's near
the end. Every risky step below has a one-line rollback.

> **Marketing site:** `polybag.app` and `www.polybag.app` are served by Cloudflare
> Pages, not this origin. Leave those DNS records exactly as they are — they're
> separate from the `*.polybag.app` wildcard, and a specific record always wins
> over the wildcard, so nothing here touches them. Pages is served internally by
> Cloudflare, so the origin firewall, Origin CA cert, and AOP never apply to it;
> only `Full (Strict)` (zone-wide) reaches it, and that's safe for Pages.

## 0. Prereqs (one-time cert material)

**Origin CA cert** (lets Cloudflare trust the origin under Full (Strict)):
- CF dashboard → SSL/TLS → Origin Server → Create Certificate
- Hostnames: `*.polybag.app` (apex `polybag.app` is the Pages marketing site and
  never served by Caddy, so its SAN is optional future-proofing only)
- Save cert → `/opt/caddy/certs/origin.pem`, key → `/opt/caddy/certs/origin.key`
- Note: an Origin CA cert is trusted by Cloudflare only, **not** browsers — so
  don't leave a hostname grey-clouded with this cert live for long (step 2 → 4).

**Custom AOP cert** (lets the origin verify it's really our Cloudflare zone):
```bash
# Generate our own CA + a client cert for Cloudflare to present.
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
  -keyout aop-ca.key -out cloudflare-aop-ca.pem -subj "/CN=polybag-aop-ca"
openssl req -newkey rsa:2048 -nodes -keyout aop-client.key -out aop-client.csr -subj "/CN=polybag-origin-pull"
openssl x509 -req -in aop-client.csr -CA cloudflare-aop-ca.pem -CAkey aop-ca.key \
  -CAcreateserial -days 3650 -out aop-client.pem
```
- Upload the **leaf** `aop-client.pem` + `aop-client.key` to Cloudflare once via
  `POST /zones/{zone}/origin_tls_client_auth/hostnames/certificates`. Cloudflare
  rejects a root CA here (`missing leaf certificate`) — it must be the leaf. Save
  the returned certificate `id`.
- Put `cloudflare-aop-ca.pem` (the CA, NOT the client key) at
  `/opt/caddy/certs/cloudflare-aop-ca.pem`.
- `chmod 600 /opt/caddy/certs/*` ; keep `aop-ca.key` offline.
- Store `CF_API_TOKEN` (scope: Zone → SSL and Certificates → Edit), `CF_ZONE_ID`,
  and `AOP_CERT_ID` (the id above) in `/opt/shared/shared-secrets.env`.
- Confirm `jq` is installed on the host.

## 1. Caddy: mount certs + a host log dir

In `/opt/caddy/docker-compose.yml`, add to the caddy service `volumes:`
```yaml
      - ./certs:/etc/caddy/certs:ro
      - /var/log/caddy:/var/log/caddy
```
`mkdir -p /var/log/caddy`.

## 2. Caddy: install the header + snippet — WITHOUT enforcement yet

Prepend `Caddyfile.head` to `/opt/caddy/Caddyfile` (above the tenant blocks), but
**comment out the `client_auth { … }` block** in the `(cf_origin)` snippet for now
— you re-enable it in step 6 once Cloudflare is presenting the cert. The Origin CA
`tls` line, `trusted_proxies`, and the access `log` stay active.

Apply `provision-tenant.patch` so future tenants get `import cf_origin`, and add
that same `import cf_origin` line to each EXISTING tenant block.
```bash
docker compose -f /opt/caddy/docker-compose.yml exec caddy caddy validate --config /etc/caddy/Caddyfile
docker compose -f /opt/caddy/docker-compose.yml exec caddy caddy reload   --config /etc/caddy/Caddyfile
```

## 3. Wire AOP enablement into provisioning (scripts only — no live impact)

Per-hostname AOP must be enabled for each tenant subdomain (the uploaded cert is
shared; the *enablement* is per host). Automate it so it can't be forgotten:

- Insert `provision-tenant-aop.snippet.sh` into `scripts/provision-tenant.sh`
  right after `ok "Caddy reloaded."`.
- Insert `deprovision-tenant-aop.snippet.sh` into `scripts/deprovision-tenant.sh`
  right after `ok "Caddy route removed."`.
- Source the secrets near the top of both scripts:
  `[[ -f "${SHARED_DIR}/shared-secrets.env" ]] && source "${SHARED_DIR}/shared-secrets.env"`
- Both rebuild the **full** hostname set from the Caddyfile and `PUT` it whole, so
  they're correct whether Cloudflare's `PUT` replaces or merges associations.
  Deprovision additionally sends the removed host as `enabled:false` (a merge
  wouldn't drop it otherwise).

Custom-domain tenants live in other Cloudflare zones and are intentionally
skipped by both hooks — they need their own zone's cert + AOP.

## 4. Canary: proxy ONE tenant first

Add a single **proxied** DNS record for one low-traffic tenant subdomain, leaving
the wildcard grey-clouded. Set SSL/TLS → Overview → **Full (Strict)**.
Verify on that host:
- the app loads normally;
- `tail -f /var/log/caddy/access.log` shows the REAL `client_ip`, not Cloudflare's.

Rollback: grey-cloud the canary record.

## 5. Bulk-enable AOP for existing hostnames (one-time)

The provisioning hook only covers new tenants; enable AOP for everything already
in the Caddyfile in one shot:
```bash
source /opt/shared/shared-secrets.env
HOSTS=$(grep -oE '^[a-z0-9][a-z0-9.-]*\.polybag\.app' /opt/caddy/Caddyfile | sort -u)
CONFIG=$(printf '%s\n' $HOSTS | jq -R . | jq -s --arg c "$AOP_CERT_ID" \
  '[.[]|{hostname:.,cert_id:$c,enabled:true}]')
curl -sS -X PUT "https://api.cloudflare.com/client/v4/zones/$CF_ZONE_ID/origin_tls_client_auth/hostnames" \
  -H "Authorization: Bearer $CF_API_TOKEN" -H "Content-Type: application/json" \
  -d "{\"config\":$CONFIG}" | jq '.success, .errors'
```
Expect `true` and an empty error array. Cloudflare is now presenting the client
cert on origin pulls (Caddy isn't requiring it yet, so this changes nothing visible).

## 6. Enforce AOP at Caddy + prove the bypass is closed

Uncomment the `client_auth { … }` block from step 2 and reload:
```bash
docker compose -f /opt/caddy/docker-compose.yml exec caddy caddy validate --config /etc/caddy/Caddyfile
docker compose -f /opt/caddy/docker-compose.yml exec caddy caddy reload   --config /etc/caddy/Caddyfile
```
Confirm the canary still serves through Cloudflare. Then prove enforcement — from
**off-box**, a direct hit that doesn't present the cert must fail the handshake:
```bash
curl --resolve <canary>.polybag.app:443:<origin-ip> https://<canary>.polybag.app
```
This MUST be rejected. If it succeeds, stop and fix — AOP isn't enforcing and the
origin is still bypassable.

Rollback: re-comment `client_auth` and reload.

## 7. Go wide

Flip the wildcard `*.polybag.app` (and any app-specific subdomain records) to
**Proxied**. Leave the `polybag.app` / `www.polybag.app` Pages records alone —
they serve marketing, not the origin. Re-verify a couple of tenants load and show
real `client_ip`s, and confirm the marketing site still loads.

## 8. fail2ban

Copy `fail2ban/filter.d/caddy-4xx.conf` and `fail2ban/jail.d/polybag.local` into
`/etc/fail2ban/`. Set the real CF token. Validate, then reload:
```bash
fail2ban-regex /var/log/caddy/access.log /etc/fail2ban/filter.d/caddy-4xx.conf
systemctl reload fail2ban
fail2ban-client status caddy-4xx
```
Trigger it deliberately (hammer a 404) and confirm an IP Access Rule appears in
the Cloudflare dashboard. (Remember: the ban shows up at CF, not in iptables.)

## 9. Firewall lockdown (LAST — can lock you out)

Open a second SSH session first.
```bash
MGMT_IP=<your.public.ip> ./ufw-cloudflare.sh
```
Verify the second session survives, then re-test the app and a tenant renewal.

## 10. App tidy-up (after origin is CF-only)

Tighten `bootstrap/app.php`: replace `trustProxies(at: '*')` with the real hop
(the Caddy/docker subnet) so a future misconfig can't reopen XFF spoofing.
Caddy already hands Laravel the correct IP via the restored XFF.

Also turn on Cloudflare's hostname-level AOP certificate-expiry alert so the
custom cert can't lapse unnoticed.

## Rollback (summary)

Config-only at every stage — nothing here changes app data:
- Enforcement issue → re-comment `client_auth`, reload Caddy.
- Proxy issue → grey-cloud the DNS record / set SSL-TLS back to the prior mode.
- Firewall issue → `ufw disable` (or delete the cf-https rules).
