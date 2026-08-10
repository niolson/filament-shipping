# Database source connection config is MySQL-only for every driver

Status: ready-for-agent

## Problem

The `db_driver` select in `DataSourceForm` offers MySQL, PostgreSQL, and SQL Server
(`app/Filament/Resources/DataSources/Schemas/DataSourceForm.php:294`), but both places
that build the actual connection hardcode MySQL-specific config regardless of the
selected driver.

`DataSourceFactory::buildDatabaseConfig()`
(`app/Services/ShipmentImport/DataSourceFactory.php`, the `config([...])` block) sets:

- `charset => 'utf8mb4'` — not a valid charset for `sqlsrv`; PostgreSQL wants `utf8`
- `collation => 'utf8mb4_unicode_ci'` — MySQL-only; meaningless for `pgsql`/`sqlsrv`
- `strict => true` — MySQL-only (drives `sql_mode`)
- `port => (int) ($settings['db_port'] ?? 3306)` — 3306 default for every driver, where
  PostgreSQL is 5432 and SQL Server is 1433

`DataSourceForm::openTestConnection()` duplicates the same block and adds
`PDO::ATTR_TIMEOUT => 10`, which `pdo_sqlsrv` does not honour the way it does for
MySQL (SQL Server uses a DSN-level `LoginTimeout`, and
`PDO::SQLSRV_ATTR_QUERY_TIMEOUT` for statements).

Two consequences:

1. **SQL Server and PostgreSQL sources are untested and probably broken.** SQL Server
   matters most — it's the dialect behind the on-prem mid-market ERPs (SAP Business
   One, Epicor, Sage 100, Dynamics GP/NAV, Macola) that the database source is the
   integration path for.
2. **The two config blocks can drift.** Because the form's test path and the import
   path build connections independently, it's possible for "Test Connection" to
   succeed while the real import fails, or vice versa. Fixing one would not fix the
   other.

## Expected behavior

Extract a single shared connection-config builder — something like
`App\Services\ShipmentImport\DatabaseConnectionConfig::build(array $settings, string $connectionName): array`
— and have both `DataSourceFactory::buildDatabaseConfig()` and
`DataSourceForm::openTestConnection()` call it. Neither should assemble connection
config inline any more.

The builder resolves per driver:

- **Default port** when `db_port` is empty: `mysql` 3306, `pgsql` 5432, `sqlsrv` 1433.
- **`mysql`**: keep current behavior (`charset` `utf8mb4`, `collation`
  `utf8mb4_unicode_ci`, `strict` true).
- **`pgsql`**: `charset` `utf8`; omit `collation` and `strict`; support optional
  `sslmode` and `search_path` settings.
- **`sqlsrv`**: omit `charset`, `collation`, and `strict` entirely. Expose optional
  `encrypt` and `trust_server_certificate` settings — Microsoft's ODBC Driver 18
  defaults to `Encrypt=yes`, so connecting to an on-prem SQL Server with a
  self-signed certificate fails unless `TrustServerCertificate=yes` is set. This is
  the single most likely first-contact failure for an on-prem ERP deployment, so it
  needs to be reachable from the form, not just config.

Also:

- Add the driver-conditional fields to the form so `sqlsrv`/`pgsql` options are
  visible only for the relevant driver, matching the existing `visible()` pattern
  around `DataSourceForm.php:438`.
- Timeouts: keep `PDO::ATTR_TIMEOUT` for `mysql`/`pgsql`; for `sqlsrv` set
  `LoginTimeout` in the connection config and `PDO::SQLSRV_ATTR_QUERY_TIMEOUT` on the
  statement. The intent documented in the existing comment — fail fast rather than
  hang until the reverse proxy 504s — must hold for all three drivers.
- Existing saved records: a `DataSource` created before this change may have `3306`
  stored in `settings.db_port` with a non-MySQL driver. Decide with the maintainer
  whether to leave those (user-visible and editable) or fix them up in a migration.
  Leaving them is probably fine — there are no known non-MySQL sources in the wild
  yet.

## Test notes

`tests/Feature/DataSourceResourceTest.php` shows the Livewire form conventions;
`tests/Feature/DatabaseSourceRawSqlGuardTest.php` shows how the database source is
exercised without a live external DB.

The valuable tests here are unit tests on the extracted builder — assert the exact
config array per driver, including that `collation`/`strict` are **absent** (not
null) for `sqlsrv`, and that port defaults resolve per driver. That gets coverage
without needing a real SQL Server.

Add a Filament form test asserting the `sqlsrv`-only fields appear when
`settings.db_driver` is `sqlsrv` and are hidden for `mysql`.

A real SQL Server round-trip needs an actual instance and can't run in CI as-is.
Note in the PR whether it was manually verified against one; if not, say so
explicitly — this issue is specifically about a path nobody has run yet.
