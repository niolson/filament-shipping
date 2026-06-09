# ADR-0001: Cache Laravel config and routes at container runtime, not image build time

## Status

Accepted

## Context

The Docker image build (`Dockerfile`, 4-stage) could run `php artisan optimize` at build
time, producing a fully pre-cached image. But Laravel's config cache bakes in values from
the environment present when the cache is generated, and the Livewire update endpoint
hash is derived from `APP_KEY`. At build time the real tenant `.env` is not available —
each tenant mounts its own `.env` (and `/opt/shared/shared-secrets.env`) at run time.

A build-time cache would therefore freeze placeholder env values into the image and
produce a Livewire endpoint hash that doesn't match the tenant's `APP_KEY`, breaking
every Livewire request. The same image must serve all tenants and all deployment modes
(standalone / shared / external).

## Decision

- `docker/entrypoint.sh` runs `php artisan optimize` (config + route caching) at
  **container startup**, after the tenant `.env` is mounted and the database is reachable.
- Only **view caching** happens at image build time — Blade compilation doesn't depend
  on env values.
- The queue container maintains its own config cache the same way.

## Consequences

- One image serves every tenant and mode; per-tenant behavior comes entirely from the
  mounted `.env` plus shared secrets injection.
- Container startup pays the optimize cost (~seconds) on every boot.
- After changing `.env`, a container **restart is required** (and `config:clear` if
  exec-ing into a running container) — config values are cached, not read live.
- Never add `env_file: .env` to the app/queue services: OS-level env vars override the
  mounted `.env` file and silently desync from the cached config.
