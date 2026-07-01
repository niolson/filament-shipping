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

# External (internet-facing) interface. DOCKER-USER sees ALL forwarded traffic in
# both directions: inbound to a published container port arrives on this interface,
# while a container's OUTBOUND connection arrives on a docker bridge (br-*/docker0).
# The allow/deny below must be pinned to the external interface — otherwise the
# port-443 DROP also matches every container's outbound HTTPS (npm/composer/carrier
# APIs), which manifests as ETIMEDOUT during builds and blocked API calls at runtime.
EXT_IF="${EXT_IF:-$(ip route show default 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="dev") {print $(i+1); exit}}')}"
EXT_IF="${EXT_IF:-eth0}"

# If Docker hasn't created the chain yet (early boot), skip; the timer retries.
iptables -L DOCKER-USER -n >/dev/null 2>&1 || { echo "DOCKER-USER (v4) not present yet; skipping"; exit 0; }

apply() {
  local ipt="$1" ranges="$2" c
  "$ipt" -F DOCKER-USER
  # Return traffic for connections initiated by containers (and CF keep-alives).
  "$ipt" -A DOCKER-USER -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN
  for c in $ranges; do
    "$ipt" -A DOCKER-USER -i "$EXT_IF" -s "$c" -p tcp -m multiport --dports 80,443 -j RETURN
    "$ipt" -A DOCKER-USER -i "$EXT_IF" -s "$c" -p udp --dport 443 -j RETURN
  done
  # Drop only NEW inbound (external interface) hits to the published web ports.
  # Container-originated traffic enters via a bridge interface and is untouched.
  "$ipt" -A DOCKER-USER -i "$EXT_IF" -p tcp -m multiport --dports 80,443 -j DROP
  "$ipt" -A DOCKER-USER -i "$EXT_IF" -p udp --dport 443 -j DROP
  "$ipt" -A DOCKER-USER -j RETURN
}

apply iptables "$CF4"
if ip6tables -L DOCKER-USER -n >/dev/null 2>&1; then
  apply ip6tables "$CF6"
fi
