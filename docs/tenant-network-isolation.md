# Tenant network isolation (shared mode)

Each shared-mode tenant runs on its own Docker network, `shared-<tenant>`, rather
than a single flat `shared` network shared by all tenants. The shared datastores
(`shared-mysql`, `shared-redis`, `gotenberg`) are attached to *every* tenant
network, so a tenant can reach the datastores it needs but has **no** network path
to any other tenant's containers.

This closes the flat-network finding from the security review (issue 16, part 2):
previously `test-app-1` could open a raw TCP socket to `demo-app-1:9000`. The
DB/Redis credential boundary already existed; this adds network-layer containment
so a future vulnerability on one tenant can't laterally reach another.

`polybag-connect` is **not** attached to tenant networks — tenants reach the OAuth
broker via its public URL (`https://connect.polybag.app`), not container-to-container.

Standalone/on-prem is unaffected: `SHARED_NETWORK` defaults to `shared`.

## How it fits together

- `docker-compose.yml` — the `shared` network uses `name: ${SHARED_NETWORK:-shared}`.
- Each tenant's `.env` sets `SHARED_NETWORK=shared-<tenant>` (done by `provision-tenant.sh`).
- `provision-tenant.sh` creates `shared-<tenant>` and connects the datastores to it.
- `deprovision-tenant.sh` disconnects the datastores and removes the network.
- `scripts/reconnect-shared-networks.sh` re-attaches datastores to all tenant
  networks — **run it after recreating shared infra** (see the gotcha below).

## ⚠️ Gotcha: recreating a shared datastore drops attachments

The per-tenant network attachments on `shared-mysql`/`shared-redis`/`gotenberg` are
made with `docker network connect` and are **not** in those containers' own compose
files. If you recreate a datastore (e.g. an image bump in `/opt/shared`), it comes
back attached only to its compose network and every tenant loses access to it.

After any such recreation, run:

```bash
/opt/tenants/<any>/scripts/reconnect-shared-networks.sh
# or from a repo checkout: ./scripts/reconnect-shared-networks.sh
```

Consider a cron safety net (idempotent):

```
*/10 * * * * /opt/tenants/test/scripts/reconnect-shared-networks.sh >/dev/null 2>&1
```

## Migrating an existing tenant from the flat `shared` network

Run per tenant (a few seconds of downtime for that tenant as its containers are
recreated onto the new network). Do one tenant, verify, then the next.

```bash
TENANT=demo                       # then repeat with test
cd /opt/tenants/$TENANT

# 1. Create the per-tenant network and attach the shared datastores.
docker network create "shared-$TENANT" 2>/dev/null || true
for svc in shared-mysql shared-redis gotenberg; do
    docker network connect "shared-$TENANT" "$svc" 2>/dev/null || true
done

# 2. Point this tenant's compose + .env at the new network.
#    (docker-compose.yml must already have: name: ${SHARED_NETWORK:-shared})
grep -q '^SHARED_NETWORK=' .env \
    && sed -i "s|^SHARED_NETWORK=.*|SHARED_NETWORK=shared-$TENANT|" .env \
    || echo "SHARED_NETWORK=shared-$TENANT" >> .env

# 3. Recreate the tenant's containers on the new network.
docker compose up -d

# 4. Verify the tenant still reaches its datastores.
docker compose exec -T app php artisan tinker --execute \
    'DB::connection()->getPdo(); echo "db ok\n"; \
     app("redis")->connection()->ping(); echo "redis ok\n";'

# 5. Verify cross-tenant reach is gone (should print BLOCKED once the OTHER
#    tenant is also off the flat network, or immediately if this one left it):
docker exec "${TENANT}-app-1" php -r \
    '$f=@fsockopen("<other-tenant>-app-1",9000,$e,$s,3); echo $f?"OPEN\n":"BLOCKED\n";'
```

Once all tenants are migrated, the flat `shared` network still holds the datastores
and `polybag-connect` but no tenant `app`/`queue`/`scheduler` containers.
