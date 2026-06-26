# Option A — Cloudflare proxy hardening runbook

Goal: orange-cloud `*.polybag.app`, with the origin reachable on :443 **only** from
Cloudflare AND only when Cloudflare presents our Authenticated-Origin-Pulls cert.

Do these in order. Steps 1–4 are safe to stage before flipping the proxy; the
firewall lockdown (step 6) is the one that can lock you out, so it's last.

## 0. Prereqs (one-time cert material)

**Origin CA cert** (lets Cloudflare trust the origin under Full (Strict)):
- CF dashboard → SSL/TLS → Origin Server → Create Certificate
- Hostnames: `*.polybag.app` and `polybag.app`
- Save cert → `/opt/caddy/certs/origin.pem`, key → `/opt/caddy/certs/origin.key`

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
- Per-hostname AOP is then *enabled per tenant* — that part is automated by the
  provisioning hook in step 2b, so you don't touch the API per tenant by hand.
- Store `CF_API_TOKEN` (scope: Zone → SSL and Certificates → Edit), `CF_ZONE_ID`,
  and `AOP_CERT_ID` (the id above) in `/opt/shared/shared-secrets.env`.

## 1. Caddy: mount certs + a host log dir

In `/opt/caddy/docker-compose.yml`, add to the caddy service `volumes:`
```yaml
      - ./certs:/etc/caddy/certs:ro
      - /var/log/caddy:/var/log/caddy
```
`mkdir -p /var/log/caddy`.

## 2. Caddy: install the header + snippet

Prepend `Caddyfile.head` to `/opt/caddy/Caddyfile` (above the tenant blocks).
Apply `provision-tenant.patch` so future tenants get `import cf_origin`, and add
that same `import cf_origin` line to each EXISTING tenant block.
```bash
docker compose -f /opt/caddy/docker-compose.yml exec caddy caddy validate --config /etc/caddy/Caddyfile
docker compose -f /opt/caddy/docker-compose.yml exec caddy caddy reload   --config /etc/caddy/Caddyfile
```

## 2b. Wire AOP enablement into provisioning

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
  wouldn't drop it otherwise). Requires `jq` on the host.

Verify the lockdown is real: from off-box, a direct hit that doesn't present the
cert must fail the TLS handshake —
`curl --resolve a-tenant.polybag.app:443:<origin-ip> https://a-tenant.polybag.app`
should be rejected. If it succeeds, AOP isn't enforcing and the bypass is open.

Custom-domain tenants live in other Cloudflare zones and are intentionally
skipped by both hooks — they need their own zone's cert + AOP.

## 3. Flip the proxy

CF dashboard → DNS → `*.polybag.app` (and apex if proxied) → **Proxied**.
SSL/TLS → Overview → **Full (Strict)**.
Load a tenant, confirm it works, then `tail -f /var/log/caddy/access.log` and
confirm `client_ip` shows REAL visitor IPs, not Cloudflare's.

## 4. fail2ban

Copy `fail2ban/filter.d/caddy-4xx.conf` and `fail2ban/jail.d/polybag.local` into
`/etc/fail2ban/`. Set the real CF token. Validate, then reload:
```bash
fail2ban-regex /var/log/caddy/access.log /etc/fail2ban/filter.d/caddy-4xx.conf
systemctl reload fail2ban
fail2ban-client status caddy-4xx
```
Trigger it deliberately (hammer a 404) and confirm an IP Access Rule appears in
the Cloudflare dashboard. (Remember: the ban shows up at CF, not in iptables.)

## 5. Firewall lockdown (LAST — can lock you out)

Open a second SSH session first.
```bash
MGMT_IP=<your.public.ip> ./ufw-cloudflare.sh
```
Verify the second session survives, then re-test the app and a tenant renewal.

## 6. App tidy-up (after origin is CF-only)

Tighten `bootstrap/app.php`: replace `trustProxies(at: '*')` with the real hop
(the Caddy/docker subnet) so a future misconfig can't reopen XFF spoofing.
Caddy already hands Laravel the correct IP via the restored XFF.

## Rollback

Grey-cloud the DNS record, `ufw disable` (or delete the cf-https rules), set
SSL/TLS back to your prior mode. Because nothing here changes app data, rollback
is config-only.
