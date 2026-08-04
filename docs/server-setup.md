# Server Setup

One-time setup for a VPS that will host PolyBag tenant instances.

## Requirements

- Ubuntu 24.04 LTS (recommended)
- 2 GB RAM minimum (shared infra), 4+ GB recommended for multiple tenants
- SSH access as root
- A domain with wildcard DNS pointing to the server (e.g. `*.polybag.app` -> server IP)

## Resource Estimates

| Component | RAM |
|---|---|
| Shared MySQL | ~300 MB |
| Shared Redis | ~16 MB (256 MB max) |
| Each tenant (app + nginx + queue) | ~120 MB |
| Caddy | ~30 MB |

With shared infra on a 2 GB server, you can comfortably run 8-10 tenants.

## 1. System Updates

```bash
apt update && apt upgrade -y
```

Reboot if the kernel was updated:

```bash
reboot
```

## 2. Firewall

If using a cloud provider firewall (Hetzner, DigitalOcean, etc.), allow inbound on:

- **22/tcp** (SSH)
- **80/tcp** (HTTP)
- **443/tcp** (HTTPS)
- **443/udp** (HTTP/3 / QUIC)

Block everything else.

## 3. Install Docker

```bash
curl -fsSL https://get.docker.com | sh
docker compose version  # verify
```

## 4. Create Docker Networks

```bash
docker network create proxy   # Caddy <-> nginx routing
docker network create shared  # Tenant app <-> shared MySQL/Redis
```

## 5. Set Up Caddy (Reverse Proxy)

Caddy runs as a Docker container on the `proxy` network, handling TLS and routing subdomains to tenant containers.

```bash
mkdir -p /opt/caddy
```

Create `/opt/caddy/Caddyfile`:

```
# Tenant entries are added by the provisioning script.
# Each entry maps a subdomain to a tenant's nginx container.
#
# Example:
# acme.polybag.app {
#     reverse_proxy acme-nginx-1:80
# }
```

Copy in the compose file — it is tracked in the repo so its image pin is kept
current by Dependabot (see `infra/README.md`):

```bash
cp <repo>/infra/caddy/docker-compose.yml /opt/caddy/docker-compose.yml
```

Start Caddy:

```bash
cd /opt/caddy && docker compose up -d
```

## 6. Shared Infrastructure Setup

Shared MySQL + Redis serve all tenants from a single instance, reducing memory usage significantly.

```bash
mkdir -p /opt/shared
cp <repo>/infra/shared/* /opt/shared/
cp <repo>/docker/mysql.cnf /opt/shared/mysql.cnf
cp <repo>/infra/.env.example /opt/shared/.env
cp <repo>/infra/shared-secrets.env.example /opt/shared/shared-secrets.env
```

`infra/shared/` carries the compose file plus the two MySQL keyring-component
configs (`mysqld.my`, `component_keyring_file.cnf`) that TDE needs mounted —
see the encryption-at-rest section below.

Edit `/opt/shared/.env` and set strong passwords:

```bash
cd /opt/shared
nano .env   # set MYSQL_ROOT_PASSWORD and REDIS_PASSWORD
```

Edit `/opt/shared/shared-secrets.env` — this file is injected into every tenant app container at runtime, so secrets here are set once and apply to all tenants. `REDIS_PASSWORD` must match the value in `.env`:

```bash
nano /opt/shared/shared-secrets.env  # set REDIS_PASSWORD, GOOGLE_CLIENT_ID/SECRET, OAUTH_BROKER_SECRET, RESEND_API_KEY
```

To rotate any shared secret later: update `shared-secrets.env` and restart all tenant containers (`deploy-tenant.sh --all`). No per-tenant `.env` edits needed.

Start shared infrastructure:

```bash
cd /opt/shared && docker compose up -d
```

Verify:

```bash
docker exec shared-mysql mysqladmin ping -h localhost
docker exec shared-redis redis-cli -a <password> ping
```

### Encryption at Rest (TDE)

Shared MySQL is configured with InnoDB tablespace encryption by default. The `mysql.cnf` file enables the `keyring_file` plugin and sets `default_table_encryption=ON`, so all new tables are encrypted automatically.

**How it works:**
- Data files on disk are encrypted with AES — queries, indexes, and search work normally (decrypted transparently at the query layer)
- The keyring file is stored in a separate Docker volume (`mysql-keyring`) from the data volume (`mysql-data`)
- Each tenant's `db:encrypt-tables` command runs on startup to encrypt any pre-existing unencrypted tables

**Copy the MySQL config to the shared directory:**

```bash
cp <repo>/docker/mysql.cnf /opt/shared/mysql.cnf
```

**Keyring backup:**

The keyring file is critical — if lost, encrypted data is unrecoverable. Back it up separately from the database:

```bash
# Find the keyring volume mount
docker volume inspect shared_mysql-keyring --format '{{ .Mountpoint }}'

# Copy the keyring file to a secure backup location (NOT the same location as DB backups)
cp /var/lib/docker/volumes/shared_mysql-keyring/_data/keyring /path/to/secure/backup/
```

Back up the keyring after initial setup and after any key rotation. Store it in a different location from your database backups (different cloud account, different physical location, or a password manager vault).

**Existing servers:** If upgrading an existing shared-mysql instance, restart it after adding the config:

```bash
cd /opt/shared && docker compose up -d
```

Then each tenant's next deploy will run `db:encrypt-tables` to encrypt existing tables.

### Gotenberg (PDF Rendering)

Renders pack slips and labels for every tenant.

```bash
mkdir -p /opt/gotenberg
cp <repo>/infra/gotenberg/docker-compose.yml /opt/gotenberg/docker-compose.yml
cd /opt/gotenberg && docker compose up -d
/opt/tenants/<any-tenant>/scripts/reconnect-shared-networks.sh
```

That last step is required. Compose attaches Gotenberg only to the `shared`
network, but tenants reach it over their own `shared-<tenant>` networks — so
every recreation drops those attachments until they are restored. The script is
idempotent. The same applies to shared MySQL and Redis.

Verify from a tenant app container:

```bash
docker exec <tenant>-app-1 curl -s -o /dev/null -w '%{http_code}\n' http://gotenberg:3000/health
```

### Uptime Kuma (Monitoring)

```bash
mkdir -p /opt/uptime-kuma
cp <repo>/infra/uptime-kuma/docker-compose.yml /opt/uptime-kuma/docker-compose.yml
cd /opt/uptime-kuma && docker compose up -d
```

Runs v2. Upgrading from the v1 tag is a one-way migration that rewrites
heartbeat history in place — back up the data volume first, and do not
interrupt it:

```bash
docker stop uptime-kuma
tar -czf /var/backups/uptime-kuma-$(date -u +%Y%m%d).tar.gz \
  -C "$(docker volume inspect uptime-kuma_data --format '{{.Mountpoint}}')" .
# then bump the pin and `docker compose up -d`, watching `docker logs -f`
```

The migration logs `[DON'T STOP]` while aggregating heartbeats and takes
minutes to hours depending on history size. An interrupted run must be
restored from backup and retried. Note the server starts listening *during*
migration, so a reachable web UI is not the completion signal — wait for the
progress lines to finish.

Expect this image to dominate the monthly scan report: v2 bundles Chromium
for browser-based monitors. See the comment in
`infra/uptime-kuma/docker-compose.yml` for why that is still an improvement
over v1.

## 7. Create Tenants Directory

```bash
mkdir -p /opt/tenants
```

## 8. Database Backups

Automated daily backups to S3-compatible storage (Hetzner Object Storage, AWS S3, etc.).

### Install AWS CLI

```bash
apt install -y awscli
```

### Configure credentials

```bash
cp <repo>/infra/backup.env.example /opt/shared/backup.env
nano /opt/shared/backup.env  # set S3_ACCESS_KEY, S3_SECRET_KEY
```

### Test the backup

```bash
/opt/tenants/<any-tenant>/scripts/backup-db.sh
```

This will dump all `polybag_*` databases, compress them, and upload to the S3 bucket. You can also back up a single database:

```bash
/opt/tenants/<any-tenant>/scripts/backup-db.sh polybag_acme
```

### Schedule daily backups

```bash
crontab -e
# Add:
0 3 * * * /opt/tenants/<any-tenant>/scripts/backup-db.sh >> /var/log/polybag-backup.log 2>&1
```

Runs daily at 03:00 UTC. Old backups are automatically pruned after `BACKUP_RETENTION_DAYS` (default 30).

### Keyring backup

The MySQL keyring file should also be backed up to S3. Add this to the same crontab:

```bash
5 3 * * * docker cp shared-mysql:/var/lib/mysql-keyring/keyring /tmp/keyring && \
  AWS_ACCESS_KEY_ID=$(grep S3_ACCESS_KEY /opt/shared/backup.env | cut -d= -f2-) \
  AWS_SECRET_ACCESS_KEY=$(grep S3_SECRET_KEY /opt/shared/backup.env | cut -d= -f2-) \
  aws s3 cp /tmp/keyring s3://polybag/backups/keyring/keyring-$(date +\%Y-\%m-\%d) \
  --endpoint-url https://hel1.your-objectstorage.com && rm /tmp/keyring
```

### Backup encryption

Backups are encrypted with AES-256 before upload. The encryption key is held in a
**versioned keyring** at `/opt/shared/backup-keys.env` so it can be rotated for
compliance without orphaning older backups:

```
BACKUP_KEY_CURRENT=2
BACKUP_KEY_1=<hex>
BACKUP_KEY_2=<hex>
```

Each backup's object name carries a `.kN` tag (e.g. `polybag_acme_2026-03-25_080000.k2.sql.gz.enc`)
recording which key version decrypts it. If no keyring exists, `backup-db.sh` falls
back to the legacy single `BACKUP_ENCRYPTION_KEY` in `backup.env` and writes untagged
objects.

**Initialize / rotate** the key with `scripts/rotate-backup-key.sh`. The first run
migrates the legacy key in as `k1` and adds a new current key; subsequent runs add the
next version. Old keys are never deleted automatically:

```bash
/opt/tenants/<any-tenant>/scripts/rotate-backup-key.sh            # rotate (annual / on compromise)
/opt/tenants/<any-tenant>/scripts/rotate-backup-key.sh --retire   # drop keys no backup still needs
```

> **Escrow the keyring off-server** (secrets manager / offline). If it is lost, every
> encrypted backup in S3 is unrecoverable; if it lives only beside the backups, encryption
> adds little protection. Retire an old key only after its tagged backups have aged out of
> the retention window (`--retire` refuses while any still reference it).

### Restore from backup

`scripts/restore-db.sh` downloads a backup, selects the right key from its `.kN` tag,
decrypts/decompresses, and loads it into a target database (restore into a scratch
database to verify backups without touching production):

```bash
# List available backups
/opt/tenants/<any-tenant>/scripts/restore-db.sh --list

# Restore into a scratch database (key chosen automatically from the .kN tag)
/opt/tenants/<any-tenant>/scripts/restore-db.sh \
  polybag_acme_2026-03-25_080000.k2.sql.gz.enc --into polybag_acme_restore
```

### Object storage credential rotation

`S3_ACCESS_KEY`/`S3_SECRET_KEY` in `/opt/shared/backup.env` authenticate to the
object storage bucket itself (Hetzner Object Storage), separate from the backup
*encryption* key above. Hetzner has no API for generating or revoking S3 keys —
that step is Console-only — so `scripts/rotate-storage-key.sh` covers everything
around it: it verifies a new key can list, write, and read the real bucket
*before* writing `backup.env`, so a bad paste never breaks the nightly backup.

```bash
# 1. Hetzner Console -> Object Storage -> generate a new access key/secret.
# 2. Run the rotation (prompts for the new key/secret; secret input is hidden):
/opt/tenants/<any-tenant>/scripts/rotate-storage-key.sh

# 3. Confirm end-to-end, then revoke the OLD key shown in the script's output
#    in the Hetzner Console.
/opt/tenants/<any-tenant>/scripts/backup-db.sh
```

Rotate this key on the same cadence as `scripts/rotate-internal-secrets.sh`
(annually, at minimum) or immediately after any suspected credential exposure or
security breach.

> There is currently no automated check anywhere (script or cron) that flags
> when `backup.env`, the backup encryption keyring, or the secrets rotated by
> `rotate-internal-secrets.sh` are overdue for rotation. Track the annual
> schedule externally (calendar reminder / key management document) until such
> a check exists.

## 9. Generate Wildcard QZ Tray Certificate (Optional)

For `*.polybag.app` tenants, a shared QZ Tray signing certificate avoids generating one per tenant:

```bash
mkdir -p /opt/shared/qz
openssl genrsa -out /opt/shared/qz/qz-private-key.pem 2048
openssl req -x509 -new -sha256 -key /opt/shared/qz/qz-private-key.pem \
  -out /opt/shared/qz/qz-certificate.pem -days 3650 \
  -subj "/CN=*.polybag.app"
```

> Keep `-sha256`: QZ Tray rejects SHA-1-signed certificates with an "Invalid
> Certificate" popup.

To pre-trust this certificate on workstations and suppress QZ Tray's Allow/Block
prompt, see [QZ Tray Certificate Provisioning](qz-tray-provisioning.md).

## 10. OAuth Broker (Optional)

The OAuth broker (`connect.<domain>`) handles OAuth authorization code flows on behalf of all PolyBag instances (shared tenants and on-prem). It holds provider client credentials centrally so individual instances don't need them. Skip this if you don't need OAuth integrations.

The broker is a separate Laravel app — see the [polybag-connect](https://github.com/niolson/polybag-connect) repo.

### Generate shared secret

Generate a secret and add it to `/opt/shared/shared-secrets.env` (the file you created during shared infra setup):

```bash
echo "OAUTH_BROKER_SECRET=$(openssl rand -hex 32)" >> /opt/shared/shared-secrets.env
```

> The provisioning script sets `OAUTH_BROKER_URL` and `OAUTH_INSTANCE_ID` in each tenant's `.env`. `OAUTH_BROKER_SECRET` is injected at runtime from `shared-secrets.env` — no per-tenant edits needed.

### Deploy the broker

```bash
git clone https://github.com/niolson/polybag-connect.git /opt/polybag-connect
cd /opt/polybag-connect
cp .env.example .env
```

Edit `/opt/polybag-connect/.env`:
- Set `SHARED_TENANT_SECRET` to the same value as `OAUTH_BROKER_SECRET` in `/opt/shared/shared-secrets.env`
- Set `REDIS_HOST=shared-redis` and `REDIS_PASSWORD` (from `/opt/shared/.env`)
- Add provider credentials (`SHOPIFY_CLIENT_ID`, `SHOPIFY_CLIENT_SECRET`, etc.)

Build and start:

```bash
cd /opt/polybag-connect && docker compose up -d --build
```

### Register on-prem instances

On-prem instances need individual secrets. Register them from inside the broker container:

```bash
docker exec -it polybag-connect-app-1 php artisan instance:register <instance-id>
```

This outputs a secret to give to the on-prem customer for their `.env`.

### Add Caddy route

Append to `/opt/caddy/Caddyfile`:

```
connect.polybag.app {
    reverse_proxy polybag-connect-app-1:8080
}
```

Reload Caddy:

```bash
docker compose -f /opt/caddy/docker-compose.yml exec caddy caddy reload --config /etc/caddy/Caddyfile
```

### Verify

```bash
curl -s -o /dev/null -w "%{http_code}" "https://connect.polybag.app/health"
# Should return 200
```

## 11. Provision Tenants

### Shared mode (default)

Uses the shared MySQL + Redis from step 6:

```bash
cd /opt/tenants
/opt/tenants/<any-tenant>/scripts/provision-tenant.sh acme
# or explicitly:
scripts/provision-tenant.sh --mode shared acme
```

The script will:
- Create a dedicated database (`polybag_acme`) and user in shared-mysql
- Set Redis prefix (`acme-`) for key isolation
- Mount QZ certs from `/opt/shared/qz/`

### Standalone mode

Per-tenant MySQL + Redis containers (higher memory, full isolation):

```bash
scripts/provision-tenant.sh --mode standalone acme
```

See `scripts/provision-tenant.sh` for details.

## 12. Security Hardening

One-time hardening applied to the server after initial setup.

### UFW Firewall

Host-level firewall (complements any cloud provider firewall from step 2):

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp    # SSH
ufw allow 80/tcp    # HTTP
ufw allow 443/tcp   # HTTPS
ufw allow 443/udp   # HTTP/3 / QUIC
ufw logging medium
ufw enable
```

> In shared/proxied mode the `:80`/`:443` allows above are only a baseline — to
> actually *restrict* them to Cloudflare, see the next subsection. ufw alone can't
> (Docker publishes those ports and bypasses ufw).

### Cloudflare-only origin lockdown (shared / proxied mode)

When the server sits behind the Cloudflare proxy (orange-cloud — see
`docs/cloudflare-hardening/`), restrict the origin web ports to Cloudflare so the
WAF/proxy can't be bypassed by hitting the origin IP directly.

> **`ufw` cannot do this.** Caddy's `:80`/`:443` are published by Docker, which
> inserts iptables rules that run *before* ufw's INPUT filtering — so `ufw deny` on
> those ports is silently ignored. The restriction must go in the `DOCKER-USER`
> chain instead.

Install the script + self-healing timer from `docs/cloudflare-hardening/option-a/`:

```bash
install -m744 cf-origin-firewall.sh /usr/local/sbin/cf-origin-firewall.sh
install -m644 systemd/cf-origin-firewall.service /etc/systemd/system/
install -m644 systemd/cf-origin-firewall.timer   /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now cf-origin-firewall.timer
systemctl start cf-origin-firewall.service
```

This drops non-Cloudflare traffic to `:80`/`:443` (v4 + v6) via `DOCKER-USER` and
reapplies every 2 min (a Docker daemon restart flushes the chain). SSH (`:22`) is a
host service in the INPUT chain, so plain `ufw` rules apply to it normally (the
Docker-bypass problem above doesn't apply to SSH) — see the next subsection for
restricting it to Tailscale instead of leaving it open to the internet.

Full context — including the Cloudflare-side setup (Origin CA cert + Authenticated
Origin Pulls) — is in `docs/cloudflare-hardening/option-a/README.md`.

### Tailscale-only SSH access (recommended)

Admins with a dynamic IP can't use a static `ufw` allowlist for SSH. Instead, join
the server to your tailnet and restrict `:22` to the `tailscale0` interface —
this closes public SSH entirely while still allowing access from any device on
the tailnet, dynamic IP or not.

```bash
curl -fsSL https://tailscale.com/install.sh | sh
tailscale up --auth-key=<one-time-key-from-the-tailscale-admin-console>
tailscale ip -4   # confirm it got a 100.x.x.x address
```

In the Tailscale admin console, **disable key expiry** for this device — headless
servers can't do the interactive browser re-auth that a normal expiring key
eventually requires, and this server has no other network path in once public
SSH is closed.

**Verify SSH-over-Tailscale works before touching the firewall** — from a device
already on the tailnet: `ssh root@<tailscale-ip>`. Only once that's confirmed:

```bash
ufw allow in on tailscale0 to any port 22 proto tcp
ufw delete allow 22/tcp
```

Verify from *outside* the tailnet that public `:22` now times out, and from a
tailnet device that it still connects. Keep the Hetzner (or provider) web console
as break-glass in case Tailscale itself is ever unreachable — see the root
password note in SSH Hardening below.

### SSH Hardening

Edit `/etc/ssh/sshd_config` (or a file in `/etc/ssh/sshd_config.d/`):

```
PermitRootLogin without-password   # Key-only, no password
PasswordAuthentication no
X11Forwarding no
MaxAuthTries 3
AllowTcpForwarding no
ClientAliveCountMax 2
LogLevel VERBOSE
```

Restart SSH after changes:

```bash
systemctl restart ssh
```

#### Console break-glass

If SSH access is ever restricted to Tailscale (see above), a cloud provider's
web console (e.g. Hetzner Cloud's VNC console) becomes the only recovery path
if the tailnet itself is ever unreachable. Check this actually works *before*
you need it — a fresh cloud image typically ships with the root account
password-**locked** (`passwd -S root` shows `L`), which silently breaks
console login even though it looks like a normal login prompt. Set a real
password if so:

```bash
passwd root   # or: echo "root:$(openssl rand -base64 24 | tr -d '/+=')" | chpasswd
```

This does **not** reopen SSH password auth — `PasswordAuthentication no` blocks
password auth for SSH at the protocol level regardless of whether the account
has a password hash. Store the password in a password manager, not in this
repo or anywhere on the box.

#### Algorithm hardening

OpenSSH's defaults still offer some weak KEX/host-key/MAC options for legacy client compatibility. Restrict to the strong set with a dedicated file in `/etc/ssh/sshd_config.d/` (e.g. `hardening-algorithms.conf`):

```
KexAlgorithms sntrup761x25519-sha512@openssh.com,curve25519-sha256,curve25519-sha256@libssh.org,diffie-hellman-group-exchange-sha256,diffie-hellman-group16-sha512,diffie-hellman-group18-sha512
HostKeyAlgorithms rsa-sha2-512,rsa-sha2-256,ssh-ed25519
Ciphers chacha20-poly1305@openssh.com,aes128-ctr,aes192-ctr,aes256-ctr,aes128-gcm@openssh.com,aes256-gcm@openssh.com
MACs umac-128-etm@openssh.com,hmac-sha2-256-etm@openssh.com,hmac-sha2-512-etm@openssh.com
```

This drops the NIST curves in KEX/host-key (suspected NSA-influenced), SHA-1 MACs, the 2048-bit `diffie-hellman-group14-sha256` KEX, and the non-`-etm` (encrypt-and-MAC) MAC variants — requires OpenSSH 8.5+/Dropbear 2018.76+ on the client, which any modern admin machine satisfies. Validate before restarting:

```bash
sshd -t && systemctl restart ssh
```

Verify with [ssh-audit](https://github.com/jtesta/ssh-audit) (`apt install ssh-audit`) — should show no `[fail]`/`[warn]` entries. **Test a brand-new connection before closing your existing session** — `sshd -t` catches syntax errors but not a config that's valid yet locks out every client you actually have.

### fail2ban

Protects against brute-force attacks by banning IPs after repeated failures.

```bash
apt install -y fail2ban
```

Create `/etc/fail2ban/jail.local`:

```ini
[DEFAULT]
maxretry = 5
findtime = 10m
bantime = 1h

[sshd]
enabled = true
maxretry = 3
bantime = 24h

[nginx-http-auth]
enabled = true

[nginx-botsearch]
enabled = true
```

```bash
systemctl enable --now fail2ban
```

Active jails: `sshd` (3 attempts, 24h ban), `nginx-http-auth`, `nginx-botsearch`. Default: 5 attempts, 1h ban, 10-minute find window.

Config: `/etc/fail2ban/jail.local`

### auditd

Kernel-level audit logging for sensitive file and syscall activity.

```bash
apt install -y auditd
systemctl enable --now auditd
```

Custom rules go in `/etc/audit/rules.d/hardening.rules`. The rules on this server monitor:

- `/etc/passwd`, `/etc/shadow`, `/etc/group`, `/etc/sudoers`, `/etc/ssh/sshd_config`
- Login/logout events
- Docker socket access
- Privilege escalation syscalls (`setuid`, `setgid`, etc.)

### AIDE (File Integrity Monitoring)

Detects unauthorized changes to critical system files.

```bash
apt install -y aide
```

> **Important:** The default AIDE config will OOM this server. Use a minimal custom config at `/etc/aide/aide.conf` that watches critical paths only.

Paths monitored on this server: `/etc/ssh`, `/etc/passwd`, `/etc/shadow`, `/etc/gshadow`, `/etc/group`, `/etc/sudoers`, `/etc/sudoers.d`, `/etc/cron.d`, `/etc/crontab`, `/etc/hosts`, `/etc/resolv.conf`, `/etc/fail2ban`, `/etc/audit`

Initialize the database (always use `nice`/`ionice` on this server — raw `aideinit` will OOM):

```bash
nice -n 19 ionice -c 3 aideinit
cp /var/lib/aide/aide.db.new /var/lib/aide/aide.db
```

Daily check at 3am via `/etc/cron.d/aide-check`, output sent to syslog via `logger -t aide`.

Database: `/var/lib/aide/aide.db`

**Never run `aideinit` without `nice -n 19 ionice -c 3` on this server.**

### Kernel / Network Hardening

Disable unused network protocols and USB storage via `/etc/modprobe.d/disable-unused-protocols.conf`:

```
install dccp /bin/true
install sctp /bin/true
install rds /bin/true
install tipc /bin/true
install usb-storage /bin/true
```

### System Hardening

**Core dumps** — disable in `/etc/security/limits.conf`:

```
* hard core 0
```

**Umask** — tighten to `027` in `/etc/login.defs`:

```
UMASK 027
```

**Additional packages:**

```bash
apt install -y debsums apt-show-versions libpam-tmpdir
```

### Postfix

If Postfix is installed, harden the banner and disable VRFY in `/etc/postfix/main.cf`:

```
smtpd_banner = ESMTP
disable_vrfy_command = yes
```

### Logwatch

Daily log summary written to `/var/log/logwatch/YYYY-MM-DD.log`.

```bash
apt install -y logwatch
```

Config: `/etc/logwatch/conf/logwatch.conf`

Runs at 6am daily via `/etc/cron.d/logwatch`. No mail relay configured — when ready, set `Output = mail` and `MailTo = you@domain.com` in the config.

### Lynis

Weekly security audit.

```bash
apt install -y lynis
```

Weekly audit every Sunday at 2am via `/etc/cron.d/lynis`. Report: `/var/log/lynis-weekly.log`.

### Trivy (Host OS Vulnerability Scanning)

CVE scanning for the host's OS packages, kernel, and system services (sshd,
Caddy, etc.) — the piece Grype's CI scans don't cover, since those only see
what's baked into the app/nginx container images, not the VPS underneath.
Lynis above audits general hardening posture; this is the CVE-by-severity
scan an auditor asking about "host vulnerability scanning" actually wants.

Install a pinned, checksum-verified release rather than piping an install
script — this runs as root, so we don't trust whatever happens to be
`latest` on scan day. Get the current pin (version + sha256) from
`scripts/security-scan-host.sh` in the app repo; the same two constants are
used here:

```bash
TRIVY_VERSION="0.72.0"
TRIVY_DEB_SHA256="9bf8aba92f524b74f8e83d53b298a7dfc6b4d60aca779217e7817e5433c73eeb"
curl -fsSL -o /tmp/trivy.deb \
  "https://github.com/aquasecurity/trivy/releases/download/v${TRIVY_VERSION}/trivy_${TRIVY_VERSION}_Linux-64bit.deb"
echo "${TRIVY_DEB_SHA256}  /tmp/trivy.deb" | sha256sum -c -
dpkg -i /tmp/trivy.deb && rm -f /tmp/trivy.deb
```

#### Scheduled scan

The scan runs monthly from cron, driven by the same
`scripts/security-scan-host.sh` used for on-demand reports — in `--local`
mode, so what gets scanned is defined in exactly one place rather than
duplicated into a separate wrapper that drifts. It comes from the tenant
checkout, so `deploy-tenant.sh` keeps it current; this is the same
arrangement as the nightly backup cron.

Monthly run, 1st of the month at 4am, via `/etc/cron.d/trivy-host-scan`:

```
SHELL=/bin/bash
PATH=/usr/local/bin:/usr/bin:/bin

0 4 1 * * root /opt/tenants/test/scripts/security-scan-host.sh --local --out-parent /var/log/trivy-host-scan --email you@example.com --keep 12 >> /var/log/polybag-security-scan.log 2>&1
```

`--out-parent` has the script build the dated directory name itself. Don't
be tempted to inline a `date +%Y%m%d` in the crontab instead — cron treats a
bare `%` as a newline and would truncate the command at the first one.

Reports land in `/var/log/trivy-host-scan/host-<host>-<timestamp>/` as a
Markdown summary plus the raw JSON, matching the layout of the on-demand
reports in `security-reports/`. `--keep 12` prunes all but the twelve most
recent, so a year of monthly scans is retained without growing without
bound — the VPS disk is the constraint here, not report volume.

#### Alerting

`--email` mails the report through Resend when the scan finishes, reusing
the `RESEND_API_KEY` already in `/opt/shared/shared-secrets.env` for the
app's own mail — no second credential to manage. Sender defaults to
`noreply@updates.polybag.app`; override with `SECURITY_SCAN_EMAIL_FROM`.
Comma-separate the value for multiple recipients.

Mail goes out on **every** run, not just when findings appear, and the
verdict is in the subject line:

```
[PolyBag] Host scan clean (no critical/high) on ubuntu-2gb-hil-1
[PolyBag] Host scan: 2 critical / 14 high on ubuntu-2gb-hil-1
[PolyBag] Host vulnerability scan FAILED on ubuntu-2gb-hil-1
```

This is deliberate. If mail only went out on findings, a cron that silently
stopped firing would look identical to a clean scan — the same silent-rot
failure the nightly backup hit once after a password rotation. A scan that
dies partway (trivy crash, full disk, vulnerability DB download failure)
mails the FAILED notice from an exit trap, so a missing monthly message is
itself the signal that something needs looking at.

Fixable findings remediate via `apt-get upgrade` per the standard patching
SLA (Critical: 7 days, High: 30 days). Note that kernel CVEs need a reboot
and a purge of the old kernel packages to actually clear — an `apt upgrade`
alone leaves the vulnerable versions installed and still reported.

#### On-demand

For an audit-ready report (e.g. to hand to a reviewer) rather than waiting
for the monthly cron, run this from a checkout of the app repo:

```bash
./scripts/security-scan-host.sh --ssh root@<server>
```

It installs/verifies the same pinned Trivy remotely, leaves no scanning
tooling behind beyond the trivy binary, and writes a dated Markdown + JSON
report into `security-reports/`, matching the existing ZAP DAST report
convention.

---

## Google SSO Setup

Google SSO allows users to sign in with their Google account instead of a password. All installs (hosted and on-prem) use the same Google Cloud project (`polybag-login`) but separate OAuth client IDs.

### For hosted tenants

The shared client ID is already configured. Just add the tenant's redirect URI:

1. Go to [Google Cloud Console](https://console.cloud.google.com) > APIs & Services > Credentials
2. Click the **polybag-hosted** OAuth client ID
3. Add `https://{tenant}.polybag.app/auth/google/callback` to Authorized redirect URIs
4. Save

The tenant enables SSO via Settings > Single Sign-On > Google SSO toggle.

### For on-prem customers

Create a separate OAuth client ID for each on-prem customer (so the secret stays under your control):

1. Go to Google Cloud Console > APIs & Services > Credentials
2. Click **Create Credentials** > OAuth client ID > Web application
3. Name: customer name (e.g. "Acme Corp")
4. Authorized redirect URI: `https://{customer-domain}/auth/google/callback`
5. Copy the **Client ID** and **Client Secret**

Send the customer:
- `GOOGLE_CLIENT_ID` — the client ID
- `GOOGLE_CLIENT_SECRET` — the client secret

They add these to their `.env` file. SSO is then enabled via Settings.

### Credentials to send on-prem customers

| Credential | Where it goes | How to get it |
|---|---|---|
| `GOOGLE_CLIENT_ID` | `.env` | Create in Google Console (per customer) |
| `GOOGLE_CLIENT_SECRET` | `.env` | Created with the client ID |
| `OAUTH_BROKER_SECRET` | `.env` | From `instance:register` command (if using OAuth broker) |
| `OAUTH_BROKER_URL` | `.env` | `https://connect.polybag.app` (if using OAuth broker) |
| `OAUTH_INSTANCE_ID` | `.env` | Generated during instance registration |

Google SSO and OAuth broker credentials are independent — a customer can use one, both, or neither.

## Mail (Resend) Setup

Transactional email (MFA login codes, etc.) is sent via [Resend](https://resend.com). One Resend account/domain (`updates.polybag.app`) serves all hosted tenants, following the same shared-secret pattern as Google SSO.

### One-time setup (hosted)

1. Sign up at [resend.com](https://resend.com) and add the `updates.polybag.app` domain
2. Add the DNS records Resend gives you (SPF, DKIM, DMARC) to the `updates.polybag.app` zone
3. Create an API key and add it to `/opt/shared/shared-secrets.env`:
   ```bash
   nano /opt/shared/shared-secrets.env  # set RESEND_API_KEY
   ```
4. Restart tenant containers to pick it up: `deploy-tenant.sh --all`

`scripts/provision-tenant.sh` already sets `MAIL_MAILER=resend` and `MAIL_FROM_ADDRESS=noreply@updates.polybag.app` in each tenant's `.env`; the API key itself is injected at runtime from `shared-secrets.env` and never written to a per-tenant `.env` (shared mode) or copied in at provision time if available (standalone mode).

### For on-prem customers

On-prem installs don't get the shared Resend account. Either:
- Point them at their own Resend (or other SMTP) account by setting `MAIL_MAILER`, `RESEND_API_KEY` (or `MAIL_HOST`/`MAIL_USERNAME`/`MAIL_PASSWORD` for plain SMTP), and `MAIL_FROM_ADDRESS` in their `.env`, or
- Leave `MAIL_MAILER=log` (the `.env.example` default) if they don't need MFA email codes — app-based MFA (`AppAuthentication`) still works without mail configured.

## Database Import via SSH Tunnel

When importing shipments from a customer's database that is behind a firewall or not directly accessible, use the built-in SSH tunnel support.

### 1. Generate a keypair on the PolyBag server

```bash
ssh-keygen -t ed25519 -f /opt/ssh-keys/customer-name -N "" -C "polybag-import"
```

Send the **public key** (`/opt/ssh-keys/customer-name.pub`) to the customer.

### 2. Customer setup (their server)

The customer creates a restricted SSH user that can only tunnel to their database:

```bash
# Create a user with no shell access
sudo useradd -m -s /usr/sbin/nologin polybag
sudo mkdir -p /home/polybag/.ssh
sudo chmod 700 /home/polybag/.ssh

# Add the public key with restrictions
echo 'no-pty,no-X11-forwarding,no-agent-forwarding,permitopen="127.0.0.1:3306" ssh-ed25519 AAAA... polybag-import' \
  | sudo tee /home/polybag/.ssh/authorized_keys
sudo chmod 600 /home/polybag/.ssh/authorized_keys
sudo chown -R polybag:polybag /home/polybag/.ssh
```

The `permitopen` directive restricts the tunnel to only forward to the database. Adjust the host and port to match where MySQL/SQL Server/etc. is listening. If the database is on a different host from the SSH server (e.g. a bastion host), use that internal IP instead of `127.0.0.1`.

### 3. Configure the PolyBag instance

Add to the tenant's `.env`:

```bash
# Import database connection
SHIPMENT_IMPORT_DB_DRIVER=mysql
SHIPMENT_IMPORT_DB_HOST=db.customer.internal   # The DB host (used as tunnel remote target)
SHIPMENT_IMPORT_DB_PORT=3306
SHIPMENT_IMPORT_DB_DATABASE=orders
SHIPMENT_IMPORT_DB_USERNAME=readonly_user
SHIPMENT_IMPORT_DB_PASSWORD=secret

# SSH tunnel
SHIPMENT_IMPORT_SSH_ENABLED=true
SHIPMENT_IMPORT_SSH_HOST=bastion.customer.com   # SSH host to connect to
SHIPMENT_IMPORT_SSH_USER=polybag
SHIPMENT_IMPORT_SSH_KEY=/opt/ssh-keys/customer-name

# Optional: override the tunnel's remote side (defaults to DB_HOST:DB_PORT)
# Use when the DB host in DB_HOST is not reachable from the SSH server
# (e.g. DB_HOST is a DNS name that only resolves externally)
# SHIPMENT_IMPORT_SSH_REMOTE_HOST=127.0.0.1
# SHIPMENT_IMPORT_SSH_REMOTE_PORT=3306
```

When `SSH_REMOTE_HOST` is not set, the tunnel forwards to `DB_HOST:DB_PORT` as seen from the SSH server. Set `SSH_REMOTE_HOST=127.0.0.1` when the database runs on the same machine as the SSH server.

### 4. Test the connection

```bash
php artisan shipments:import --dry-run
```

### How it works

The tunnel opens automatically when the import runs, forwards a random local port through SSH to the remote database, and closes when the import finishes. No persistent tunnel or background service is needed.

### Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `Permission denied (publickey,password)` | Key not installed or wrong permissions on remote | Check `authorized_keys` exists, permissions are `600`, owned by the SSH user |
| `Connection refused` on SSH | SSH not running or wrong port | Verify `SHIPMENT_IMPORT_SSH_HOST` and `SSH_PORT` |
| `Cannot connect to import database` | Tunnel opened but DB rejected connection | Check `DB_USERNAME`/`DB_PASSWORD`, and that the DB allows connections from `127.0.0.1` (or whatever `SSH_REMOTE_HOST` is set to) |
| `SSH tunnel timed out` | Firewall blocking SSH or `permitopen` mismatch | Check that the SSH port is open and `permitopen` in `authorized_keys` matches the DB host:port |

## On-Premise Installation

For single-tenant on-premise deployments (no Caddy, direct port access):

```bash
git clone https://github.com/niolson/polybag.git
cd polybag
./scripts/install-onprem.sh
```

The installer will:
1. Prompt for domain/IP
2. Generate database and Redis passwords
3. Build and start containers in standalone mode
4. Generate app key and QZ Tray certificate
5. Print instructions for creating the first admin user

On-prem uses `docker-compose.onprem.yml` to publish nginx ports directly (no reverse proxy needed).
