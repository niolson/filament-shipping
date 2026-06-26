# Option B — cloudflared tunnel hardening runbook

Goal: the origin has NO inbound web ports. All traffic arrives through an
outbound `cloudflared` tunnel; Caddy serves plain HTTP internally and host-routes
to tenants exactly as before. No origin certs, no ACME, no AOP, no IP allowlist.

Do these in order. The tunnel must be proven working (step 4) BEFORE you close
the firewall (step 5), or you'll have no path in.

## 1. Create the tunnel

```bash
mkdir -p /opt/cloudflared && cd /opt/cloudflared
cloudflared tunnel login                 # browser auth -> ~/.cloudflared/cert.pem
cloudflared tunnel create polybag        # prints <TUNNEL_ID>, writes <TUNNEL_ID>.json
```
Move/copy `<TUNNEL_ID>.json` into `/opt/cloudflared/`. Put `config.yml` and
`docker-compose.yml` (from this set) alongside it, substituting `<TUNNEL_ID>`.

Add the wildcard DNS record by hand (cloudflared can't create a wildcard route):
- CF dashboard → DNS → CNAME `*` → `<TUNNEL_ID>.cfargotunnel.com`, **Proxied**.

## 2. Caddy: drop public TLS, mount a host log dir

In `/opt/caddy/docker-compose.yml`, add to the caddy service `volumes:`
```yaml
      - /var/log/caddy:/var/log/caddy
```
`mkdir -p /var/log/caddy`. (No `certs` mount needed in Option B.)

Prepend `Caddyfile.head` to `/opt/caddy/Caddyfile`. Its `trusted_proxies` is
already set to test.polybag.app's current proxy subnet (`172.18.0.0/16`).
Re-confirm if the network has been recreated since:
```bash
docker network inspect proxy -f '{{(index .IPAM.Config 0).Subnet}}'
```
Apply `provision-tenant.patch`, and add `import cf_origin` to existing tenant
blocks. Validate + reload:
```bash
docker compose -f /opt/caddy/docker-compose.yml exec caddy caddy validate --config /etc/caddy/Caddyfile
docker compose -f /opt/caddy/docker-compose.yml exec caddy caddy reload   --config /etc/caddy/Caddyfile
```

## 3. Start the tunnel

```bash
cd /opt/cloudflared && docker compose up -d
docker compose logs -f cloudflared      # expect "Registered tunnel connection" x4
```

## 4. Verify END TO END while the origin is STILL reachable directly

- Load a tenant over https — it should serve through the tunnel.
- `tail -f /var/log/caddy/access.log` → confirm `client_ip` is the REAL visitor
  (CF-Connecting-IP), not the cloudflared/docker address.
- Confirm the app sees https: a quick check that login/secure cookies work
  (X-Forwarded-Proto=https must survive the plain-HTTP internal hop — it does,
  because cloudflared is a trusted proxy).

## 5. fail2ban

Same as Option A: copy `fail2ban/filter.d/caddy-4xx.conf` and
`fail2ban/jail.d/polybag.local`, set the CF token, validate, reload.
```bash
fail2ban-regex /var/log/caddy/access.log /etc/fail2ban/filter.d/caddy-4xx.conf
systemctl reload fail2ban
```
Bans still go to the Cloudflare edge via API — a local firewall ban can't see
the real visitor IP behind the tunnel either.

## 6. Firewall lockdown (LAST — can lock you out)

Open a second SSH session first.
```bash
MGMT_IP=<your.public.ip> ./ufw-denyall.sh
```
Verify the second session survives, then reload a tenant. Inbound is now SSH-only.

## 7. App tidy-up

Tighten `bootstrap/app.php`: replace `trustProxies(at: '*')` with the proxy
subnet, now that the origin is unreachable except via the tunnel.

## Operational notes specific to Option B

- **cloudflared is a shared SPOF.** If both replicas die or the tunnel token is
  revoked, every tenant goes down and there is NO direct fallback (the firewall
  blocks it). Keep 2+ replicas; write the "tunnel down" recovery step
  (`ufw disable` to temporarily reopen, or restart cloudflared) somewhere on-call
  can find it.
- **Rollback:** `ufw disable` (or re-add 80/443), grey-cloud the DNS record OR
  point it back at an A record, `docker compose down` the tunnel. Config-only;
  no app/data changes.
- **SSH note:** the origin IP is still discoverable via :22. To make the origin
  fully invisible you'd also move SSH behind Cloudflare Access — probably overkill
  for a single-admin box, but it's the remaining exposed surface.
