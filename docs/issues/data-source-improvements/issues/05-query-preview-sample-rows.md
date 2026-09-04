# "Test Queries" validates syntax but shows no rows

Status: ready-for-agent

## Problem

The `test_queries` action (`app/Filament/Resources/DataSources/Schemas/DataSourceForm.php:427`)
calls `$pdo->prepare()` on each configured query and reports "All queries valid" or
the `PDOException` message. It never executes anything, so the operator learns the SQL
parses but not whether it returns the right rows, or whether the field mapping
resolves to sensible values.

Two further gaps:

- **The syntax check is weaker than it looks on non-MySQL drivers.** It only means
  something because emulated prepares are disabled first, and that's done for MySQL
  only (`if (($get('settings.db_driver') ?? 'mysql') === 'mysql')`). On `sqlsrv` and
  `pgsql`, `prepare()` can succeed without the server validating the statement, so
  invalid SQL may report as valid.
- **Configuring a source is guesswork.** Someone pointing PolyBag at an unfamiliar
  ERP schema has to save, run a real import, and read logs to find out whether their
  query and field mapping are right.

This matters beyond convenience: pasting a query and immediately seeing five real
rows with the mapping applied is the core of a live demo against a prospect's own
database, and it's what makes per-customer implementation cheap enough for the
mid-market to be viable.

## Expected behavior

Replace the notification-only result with a modal preview.

- **Execute the shipments query** with a row limit and render the first ~5 rows in a
  table. Show two views of each row: the raw source columns, and the mapped internal
  fields after `FieldMapper::mapShipment()` runs, so a mismatched mapping is visible
  as an empty or wrong internal field rather than an import-time surprise.
- **Execute the items query** for one previewed shipment, binding
  `shipment_reference` from the first row's resolved source record ID (mirror how
  `DatabaseSource::fetchShipmentItems()` binds it).
- **Read-only, enforced.** Run every previewed statement through
  `RawSqlGuard::assertStatementType($query, RawSqlGuard::READ, ...)` exactly as
  `DatabaseSource::fetchShipments()` does. Do **not** preview
  `mark_exported_query` or `export_query` — those are writes. Keep the existing
  prepare-only check for them so they still get some validation.
- **Row limiting must not rewrite the operator's SQL.** Don't string-append `LIMIT` —
  dialects differ (`LIMIT` vs `TOP` vs `FETCH FIRST`) and the query may already have
  one. Execute and stop consuming after N rows instead (e.g. `PDOStatement::fetch()`
  in a bounded loop, or Laravel's `cursor()`), so the same code path works on all
  three drivers.
- **Audit it.** Preview executes SQL against a customer database, so it belongs in the
  same audit trail as a real import. Follow the `executeLogged()` /
  `AuditAction` pattern in `DatabaseSource`, and see
  `tests/Feature/AuditDataSourceQueryTest.php` for the expected shape.
- **Don't log row contents.** The audit entry records that a preview ran and the
  statement, not the returned data — previewed rows are customer order data,
  including names and addresses. Render them in the modal only.
- Keep the failure path as it is today: per-query error messages, surfaced with the
  query label so the operator knows which one broke.

## Test notes

`tests/Feature/DataSourceResourceTest.php` for the form/action conventions and
`tests/Feature/DatabaseSourceRawSqlGuardTest.php` for exercising the source against a
sqlite/MySQL test connection rather than a live external database.

Worth asserting:

- A `SELECT` preview returns rows and the mapped output contains the expected internal
  keys for a configured `field_mapping`.
- A non-read statement in `shipments_query` is rejected by `RawSqlGuard` and never
  executed.
- `mark_exported_query` and `export_query` are not executed by the preview.
- The row limit holds — a query matching 1,000 rows yields only the preview count.
- An audit row is written, and it does not contain row data.
