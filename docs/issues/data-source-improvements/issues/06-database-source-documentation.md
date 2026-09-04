# Database data source has no documentation

Status: ready-for-agent

## Problem

The database data source is configurable entirely through the Filament form, and
nothing explains how to configure it. There's no reference for what the queries must
return, how `field_mapping` resolves, what database privileges are needed, when the
SSH tunnel is required, or what the `max_affected_rows` guard does.

This is the integration path for on-prem ERPs running on SQL Server, so it's the one
that gets configured against unfamiliar schemas by someone who isn't the author. It's
also the source that requires credentials into a customer's production ERP database,
which makes "what permissions does this actually need" a question with a security
answer, not just a convenience answer.

## Expected behavior

Add `docs/data-sources/database.md` covering:

**Setup**
- Connection fields per driver, and the driver-specific options (see issue `04` — SQL
  Server TLS/`TrustServerCertificate` in particular, which is the most likely
  first-contact failure against an on-prem instance).
- When the SSH tunnel is needed and how the host-key fields work (`SshTunnel`).
- Where the password is stored — the encrypted `secret_settings` column via
  `DataSource::secret()`, not `settings`.

**Queries**
- The contract for `shipments_query`: what columns it must return for `FieldMapper` to
  produce a valid shipment, and which internal fields are required vs optional.
- The contract for `shipment_items_query`, including the bound `shipment_reference`
  parameter and that it's bound, not interpolated.
- `mark_exported_query` and `export_query`: when they run, what's bound, and how
  `max_affected_rows` bounds the damage a bad `UPDATE` can do.
- That all read queries are constrained to read statements by `RawSqlGuard`, and what
  happens when a query violates that.
- Default table-based mode (`shipments_table` / `filters`) as the simpler alternative
  to custom SQL.

**Field mapping**
- A table of internal field → default source column, derived from `FieldMapper`, and
  how to override.
- The multi-client case: `client_column` and how `_client_column_value` resolves to a
  `Client`.

**Operating it**
- Recommended database privileges: a **read-only** user for import, with write
  access narrowed to only what `mark_exported_query` needs. Include example `GRANT`
  statements for MySQL and SQL Server.
- Scheduling (`schedule_interval`) and how to trigger a manual import.
- Troubleshooting: where import logs land (`storage/logs/shipment-import-*.log`) and
  the common failure modes.

**A worked example**
- One end-to-end example — connection, both queries, field mapping — against a
  realistic order/order-line schema.

Link it from `CLAUDE.md`'s Data Import / Export section and from the Data Source
resource form (a `helperText` link on the driver select, or an infolist note).

## Test notes

Documentation, so no automated tests. Two things to verify manually rather than
assume:

- The field-mapping table must be generated from what `FieldMapper` actually does, not
  from memory — read the class and confirm each default.
- The `GRANT` examples should be run against a real instance before publishing.

The per-ERP worked examples (Business One, Epicor, Sage) can't be written accurately
without access to a real schema. Scope this issue to one generic example plus the
reference material, and add ERP-specific appendices as real deployments happen.
