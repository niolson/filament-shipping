#!/usr/bin/env bash
# ufw-cloudflare.sh — restrict origin :443 to Cloudflare edge ranges.
#
# Idempotent: re-fetches the current Cloudflare list, drops the previous
# cf-https rules, and re-adds fresh ones (so it self-heals if CF ever changes
# a range). SSH from your management IP is added FIRST and never deleted, so
# you cannot lock yourself out.
#
# Usage:  MGMT_IP=203.0.113.7 ./ufw-cloudflare.sh
#    or:  ./ufw-cloudflare.sh 203.0.113.7
set -euo pipefail

MGMT_IP="${MGMT_IP:-${1:-}}"
if [[ -z "${MGMT_IP}" ]]; then
	echo "Usage: MGMT_IP=<your.public.ip> $0   (or pass it as the first arg)" >&2
	exit 1
fi

TAG="cf-https"

echo "[*] Guaranteeing SSH stays open from ${MGMT_IP} ..."
ufw allow from "${MGMT_IP}" to any port 22 proto tcp comment 'mgmt-ssh'

echo "[*] Fetching current Cloudflare ranges ..."
mapfile -t CF_RANGES < <(
	{ curl -fsS https://www.cloudflare.com/ips-v4; echo;
	  curl -fsS https://www.cloudflare.com/ips-v6; } \
	| grep -E '^[0-9a-fA-F:.]+/[0-9]+$'
)
if (( ${#CF_RANGES[@]} < 10 )); then
	echo "[!] Only fetched ${#CF_RANGES[@]} ranges — looks wrong, refusing to continue." >&2
	exit 1
fi

echo "[*] Removing stale ${TAG} rules (highest number first) ..."
while read -r num; do
	[[ -n "${num}" ]] && ufw --force delete "${num}"
done < <(ufw status numbered | awk -v t="${TAG}" '$0 ~ t { if (match($0,/\[[ 0-9]+\]/)) { n=substr($0,RSTART+1,RLENGTH-2); gsub(/ /,"",n); print n } }' | sort -rn)

echo "[*] Allowing :443 from ${#CF_RANGES[@]} Cloudflare ranges ..."
for cidr in "${CF_RANGES[@]}"; do
	ufw allow from "${cidr}" to any port 443 proto tcp comment "${TAG}"
done

echo "[*] Default-deny inbound, allow outbound ..."
ufw default deny incoming
ufw default allow outgoing

echo "[*] Enabling ufw ..."
ufw --force enable

ufw status verbose
echo
echo "[OK] Done. BEFORE you close this session, open a SECOND ssh session to"
echo "     confirm you are still reachable. Port 443 is now Cloudflare-only."
