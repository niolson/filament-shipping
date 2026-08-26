<?php

namespace App\Services\ShipmentImport;

/**
 * Builds a Laravel database connection config from the flat `settings` of a
 * Database `DataSource`.
 *
 * Each driver takes a different shape, and the wrong keys are not harmless:
 * Laravel's Postgres connector writes `charset` into the DSN as
 * `client_encoding`, so a MySQL-flavoured `utf8mb4` makes every PostgreSQL
 * connection fail at connect time. `collation` and `strict` are MySQL-only, and
 * SQL Server needs its TLS arguments instead. Both the import runtime
 * (`DataSourceFactory`) and the form's Test Connection action build their
 * connection here so the connection being tested is the connection that runs.
 */
class ImportConnectionConfig
{
    /** @var array<string, string> Selectable drivers, keyed by Laravel driver name. */
    public const DRIVERS = [
        'mysql' => 'MySQL / MariaDB',
        'pgsql' => 'PostgreSQL',
        'sqlsrv' => 'SQL Server',
        'sqlite' => 'SQLite',
    ];

    /** @var array<string, int> Conventional listening port per driver. */
    private const DEFAULT_PORTS = [
        'mysql' => 3306,
        'pgsql' => 5432,
        'sqlsrv' => 1433,
    ];

    /**
     * The conventional port for a driver, used as the form default when the
     * driver is switched. Null for drivers that do not connect over TCP.
     */
    public static function defaultPort(?string $driver): ?int
    {
        return self::DEFAULT_PORTS[$driver] ?? null;
    }

    /**
     * Whether the driver connects over TCP, and so can be probed for
     * reachability and routed through an SSH tunnel.
     */
    public static function usesHost(?string $driver): bool
    {
        return isset(self::DEFAULT_PORTS[$driver ?? '']);
    }

    /**
     * Bound how long a connection attempt may take, using whichever mechanism the
     * driver actually supports.
     *
     * pdo_sqlsrv rejects `PDO::ATTR_TIMEOUT` outright — it throws
     * `SQLSTATE[IMSSP]: An unsupported attribute was designated on the PDO
     * object` before it ever reaches the server, so passing it would make every
     * SQL Server connection fail regardless of whether the credentials are good.
     * Its connect timeout is the `LoginTimeout` DSN keyword instead, which
     * Laravel exposes as `login_timeout`. Note that msodbcsql retries a failed
     * connection once, so the wall-clock bound is roughly twice `$seconds`.
     *
     * @param  array<string, mixed>  $config  A config from {@see self::build()}.
     * @return array<string, mixed>
     */
    public static function withConnectTimeout(array $config, int $seconds): array
    {
        return match ($config['driver'] ?? null) {
            'sqlsrv' => $config + ['login_timeout' => $seconds],
            'sqlite' => $config,
            default => $config + ['options' => [\PDO::ATTR_TIMEOUT => $seconds]],
        };
    }

    /**
     * @param  array<string, mixed>  $settings  Flat DataSource settings (`db_*` keys).
     * @param  string|null  $password  Resolved separately — it lives in the encrypted secrets.
     * @return array<string, mixed>
     */
    public static function build(array $settings, ?string $password = null): array
    {
        $driver = $settings['db_driver'] ?? 'mysql';

        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => $settings['db_database'] ?? '',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ];
        }

        $config = [
            'driver' => $driver,
            'host' => $settings['db_host'] ?? '127.0.0.1',
            'port' => (int) (($settings['db_port'] ?? null) ?: self::defaultPort($driver)),
            'database' => $settings['db_database'] ?? null,
            'username' => $settings['db_username'] ?? null,
            'password' => $password,
            'prefix' => '',
        ];

        return match ($driver) {
            'pgsql' => $config + [
                'charset' => 'utf8',
                'sslmode' => 'prefer',
                'search_path' => ($settings['db_schema'] ?? null) ?: 'public',
            ],
            // msodbcsql18 flipped the default to Encrypt=yes, so an on-prem
            // SQL Server with a self-signed certificate now fails the handshake
            // unless the certificate is explicitly trusted.
            'sqlsrv' => $config + [
                'encrypt' => ($settings['db_encrypt'] ?? true) ? 'yes' : 'no',
                'trust_server_certificate' => ($settings['db_trust_server_certificate'] ?? false) ? 'yes' : 'no',
            ],
            default => $config + [
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
            ],
        };
    }
}
