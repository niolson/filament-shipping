<?php

use App\Services\ShipmentImport\ImportConnectionConfig;

$base = [
    'db_host' => 'db.example.test',
    'db_database' => 'erp',
    'db_username' => 'importer',
];

it('builds a MySQL connection with the MySQL-only keys', function () use ($base) {
    $config = ImportConnectionConfig::build($base + ['db_driver' => 'mysql'], 'secret');

    expect($config)->toMatchArray([
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'port' => 3306,
        'database' => 'erp',
        'username' => 'importer',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'strict' => true,
    ]);
});

it('never sends utf8mb4 to PostgreSQL', function () use ($base) {
    // Laravel writes `charset` into the Postgres DSN as `client_encoding`, so a
    // MySQL charset here makes every connection fail at connect time.
    $config = ImportConnectionConfig::build($base + ['db_driver' => 'pgsql']);

    expect($config['charset'])->toBe('utf8')
        ->and($config['port'])->toBe(5432)
        ->and($config['search_path'])->toBe('public')
        ->and($config)->not->toHaveKeys(['collation', 'strict']);
});

it('honours a custom PostgreSQL schema', function () use ($base) {
    $config = ImportConnectionConfig::build($base + ['db_driver' => 'pgsql', 'db_schema' => 'wms']);

    expect($config['search_path'])->toBe('wms');
});

it('builds a SQL Server connection with TLS arguments and no MySQL keys', function () use ($base) {
    $config = ImportConnectionConfig::build($base + ['db_driver' => 'sqlsrv']);

    expect($config)->toMatchArray([
        'driver' => 'sqlsrv',
        'port' => 1433,
        'encrypt' => 'yes',
        'trust_server_certificate' => 'no',
    ])->and($config)->not->toHaveKeys(['charset', 'collation', 'strict']);
});

it('lets SQL Server trust a self-signed certificate', function () use ($base) {
    $config = ImportConnectionConfig::build($base + [
        'db_driver' => 'sqlsrv',
        'db_trust_server_certificate' => true,
    ]);

    expect($config['trust_server_certificate'])->toBe('yes');
});

it('builds a SQLite connection without host or credentials', function () {
    $config = ImportConnectionConfig::build([
        'db_driver' => 'sqlite',
        'db_database' => '/data/erp.sqlite',
    ]);

    expect($config)->toBe([
        'driver' => 'sqlite',
        'database' => '/data/erp.sqlite',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
});

it('falls back to the default port when none is configured', function (string $driver, int $expected) use ($base) {
    $config = ImportConnectionConfig::build($base + ['db_driver' => $driver, 'db_port' => '']);

    expect($config['port'])->toBe($expected);
})->with([
    ['mysql', 3306],
    ['pgsql', 5432],
    ['sqlsrv', 1433],
]);

it('keeps an explicitly configured port', function () use ($base) {
    $config = ImportConnectionConfig::build($base + ['db_driver' => 'pgsql', 'db_port' => 6432]);

    expect($config['port'])->toBe(6432);
});

it('only treats networked drivers as host-based', function () {
    expect(ImportConnectionConfig::usesHost('mysql'))->toBeTrue()
        ->and(ImportConnectionConfig::usesHost('pgsql'))->toBeTrue()
        ->and(ImportConnectionConfig::usesHost('sqlsrv'))->toBeTrue()
        ->and(ImportConnectionConfig::usesHost('sqlite'))->toBeFalse()
        ->and(ImportConnectionConfig::usesHost(null))->toBeFalse();
});

it('never sends PDO::ATTR_TIMEOUT to SQL Server', function () use ($base) {
    // pdo_sqlsrv throws SQLSTATE[IMSSP] on that attribute, which would make every
    // SQL Server connection fail before it reached the server.
    $config = ImportConnectionConfig::withConnectTimeout(
        ImportConnectionConfig::build($base + ['db_driver' => 'sqlsrv']),
        10,
    );

    expect($config)->not->toHaveKey('options')
        ->and($config['login_timeout'])->toBe(10);
});

it('uses PDO::ATTR_TIMEOUT for drivers that support it', function (string $driver) use ($base) {
    $config = ImportConnectionConfig::withConnectTimeout(
        ImportConnectionConfig::build($base + ['db_driver' => $driver]),
        10,
    );

    expect($config['options'])->toBe([PDO::ATTR_TIMEOUT => 10])
        ->and($config)->not->toHaveKey('login_timeout');
})->with(['mysql', 'pgsql']);

it('applies no connect timeout to a file-based driver', function () {
    $config = ImportConnectionConfig::build(['db_driver' => 'sqlite', 'db_database' => '/tmp/erp.sqlite']);

    expect(ImportConnectionConfig::withConnectTimeout($config, 10))->toBe($config);
});
