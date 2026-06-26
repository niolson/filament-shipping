#!/usr/bin/env bash
# ufw-denyall.sh — Option B firewall: deny ALL inbound except SSH from the
# management IP. No :80/:443 at all — the only ingress is the outbound tunnel,
# so there is nothing for the public internet to reach.
#
# Usage:  MGMT_IP=203.0.113.7 ./ufw-denyall.sh
#    or:  ./ufw-denyall.sh 203.0.113.7
set -euo pipefail

MGMT_IP="${MGMT_IP:-${1:-}}"
if [[ -z "${MGMT_IP}" ]]; then
	echo "Usage: MGMT_IP=<your.public.ip> $0   (or pass it as the first arg)" >&2
	exit 1
fi

echo "[*] Guaranteeing SSH stays open from ${MGMT_IP} ..."
ufw allow from "${MGMT_IP}" to any port 22 proto tcp comment 'mgmt-ssh'

echo "[*] Removing any leftover public web rules from a prior setup ..."
# Drop highest-numbered first so numbering stays stable. Matches old Option-A
# 'cf-https' rules and any bare 80/443 allows.
while read -r num; do
	[[ -n "${num}" ]] && ufw --force delete "${num}"
done < <(ufw status numbered | awk '/cf-https|(^|[^0-9])80\/tcp|(^|[^0-9])443\/tcp/ { if (match($0,/\[[ 0-9]+\]/)) { n=substr($0,RSTART+1,RLENGTH-2); gsub(/ /,"",n); print n } }' | sort -rn)

echo "[*] Default-deny inbound, allow outbound (tunnel needs egress) ..."
ufw default deny incoming
ufw default allow outgoing

echo "[*] Enabling ufw ..."
ufw --force enable

ufw status verbose
echo
echo "[OK] Inbound is SSH-only. Confirm a SECOND ssh session before closing this one."
echo "     The app is reachable solely through the cloudflared tunnel now."
