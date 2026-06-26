# Cloudflare proxy hardening

Two complete, mutually-exclusive ways to put the `*.polybag.app` shared server
behind Cloudflare so we can use the WAF / security tooling. Both are config-only
and reversible; neither changes app data. Pick one.

The deployment fronts each tenant's nginx with a shared **Caddy** edge
(`Cloudflare → Caddy → nginx → app`), so all the real-IP and origin work happens
at Caddy, not nginx. Both options also assume bans are pushed to the Cloudflare
edge via fail2ban's API action — a local iptables/ufw ban is a no-op once the L3
source is always a Cloudflare IP.

## [Option A](./option-a/) — orange-cloud proxy + origin cert + DOCKER-USER lockdown

- DNS proxied; SSL/TLS Full (Strict) with a Cloudflare **Origin CA** cert on Caddy.
- Origin `:80`/`:443` restricted to Cloudflare ranges via the **`DOCKER-USER`**
  iptables chain (plain ufw can't filter Docker-published ports).
- **Authenticated Origin Pulls (custom cert)** is the control that actually closes
  the "any Cloudflare customer can reach your origin" bypass — without it, an IP
  allowlist alone is bypassable. AOP enablement is per-hostname and is automated
  into the provision/deprovision scripts.
- Keeps a local fallback path (grey-cloud + open firewall) if Cloudflare misbehaves.
- Cost: origin cert + per-tenant AOP API call + an IP list to (rarely) refresh.

## [Option B](./option-b/) — cloudflared tunnel + plain-HTTP origin + deny-all

- A `cloudflared` tunnel replaces the public listener entirely; Caddy serves plain
  HTTP on the private network (no origin cert, no ACME, no AOP).
- ufw denies **all** inbound except SSH — the origin has no public web port.
- The bypass class doesn't exist: nothing is directly reachable to bypass.
- Cost: `cloudflared` becomes a shared single point of failure with **no local
  fallback** — needs 2+ replicas and a written "tunnel down" recovery step.

## Choosing

| | Option A | Option B |
|---|---|---|
| Origin certs / ACME | Origin CA cert required | none |
| Bypass closer | custom-cert AOP (per-tenant) | n/a (no inbound listener) |
| Firewall | DOCKER-USER: :80/:443 to CF only | deny-all inbound |
| New failure mode | cert/AOP misconfig | tunnel down = all tenants down, no fallback |
| Files to maintain | certs + AOP + CF-range firewall | one tunnel + token |

For the **Amazon SP-API network-protection questionnaire**, both let you attest
truthfully — but the bare IP allowlist is the weakest line in a submission. Option
A is only as strong as B *if* custom-cert AOP is actually in place; Option B gives
the caveat-free "no inbound ports; backend unreachable except via authenticated
tunnel" answer.

Each option's `README.md` is the full ordered runbook.
