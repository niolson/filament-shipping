#!/usr/bin/env bash
# Restrict the Docker-published web ports (80,443 tcp + 443 udp) to Cloudflare
# ranges via the DOCKER-USER iptables chain.
#
# WHY NOT ufw: Docker publishes ports by inserting its own iptables rules that
# run BEFORE ufw's INPUT filtering, so `ufw deny`/allow on 80/443 is silently
# ineffective for container-published ports. The DOCKER-USER chain is the
# supported hook that Docker evaluates first for forwarded (published) traffic.
#
# SSH (:22) is a host service in the INPUT chain and is intentionally NOT touched
# here — it stays key-only + fail2ban, not IP-pinned (admin IP is dynamic).
#
# Deploy: install at /usr/local/sbin/cf-origin-firewall.sh (chmod +x) and run via
# the cf-origin-firewall.service/.timer units (reapplies on boot + every 2 min,
# self-healing after a Docker daemon restart flushes DOCKER-USER).
set -euo pipefail

# Cloudflare published ranges (https://www.cloudflare.com/ips/). Stable for years;
# re-check if Cloudflare ever updates them.
CF4="173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22 141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20 197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13 104.24.0.0/14 172.64.0.0/13 131.0.72.0/22"
CF6="2400:cb00::/32 2606:4700::/32 2803:f800::/32 2405:b500::/32 2405:8100::/32 2a06:98c0::/29 2c0f:f248::/32"

# If Docker hasn't created the chain yet (early boot), skip; the timer retries.
iptables -L DOCKER-USER -n >/dev/null 2>&1 || { echo "DOCKER-USER (v4) not present yet; skipping"; exit 0; }

apply() {
  local ipt="$1" ranges="$2" c
  "$ipt" -F DOCKER-USER
  "$ipt" -A DOCKER-USER -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN
  for c in $ranges; do
    "$ipt" -A DOCKER-USER -s "$c" -p tcp -m multiport --dports 80,443 -j RETURN
    "$ipt" -A DOCKER-USER -s "$c" -p udp --dport 443 -j RETURN
  done
  "$ipt" -A DOCKER-USER -p tcp -m multiport --dports 80,443 -j DROP
  "$ipt" -A DOCKER-USER -p udp --dport 443 -j DROP
  "$ipt" -A DOCKER-USER -j RETURN
}

apply iptables "$CF4"
if ip6tables -L DOCKER-USER -n >/dev/null 2>&1; then
  apply ip6tables "$CF6"
fi
