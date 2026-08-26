<?php

use App\Models\DataSource;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers a connection for a host-based database source', function () {
    $dataSource = DataSource::factory()->create([
        'source_type' => DatabaseSource::class,
        'settings' => [
            'db_driver' => 'pgsql',
            'db_host' => 'pg.example.test',
            'db_port' => 5432,
            'db_database' => 'erp',
            'db_username' => 'importer',
        ],
    ]);

    app(DataSourceFactory::class)->make($dataSource);

    $connection = config("database.connections.import_{$dataSource->id}");

    expect($connection['driver'])->toBe('pgsql')
        ->and($connection['host'])->toBe('pg.example.test')
        // The MySQL charset would make libpq reject the connection outright.
        ->and($connection['charset'])->toBe('utf8');
});

it('registers a connection for a file-based source that has no host', function () {
    // A SQLite source has no db_host to gate on, so gating purely on that key
    // would leave its connection unregistered and every query would fail.
    $dataSource = DataSource::factory()->create([
        'source_type' => DatabaseSource::class,
        'settings' => [
            'db_driver' => 'sqlite',
            'db_database' => '/tmp/erp.sqlite',
        ],
    ]);

    app(DataSourceFactory::class)->make($dataSource);

    expect(config("database.connections.import_{$dataSource->id}"))->toBe([
        'driver' => 'sqlite',
        'database' => '/tmp/erp.sqlite',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
});

it('passes SQL Server TLS settings through to the connection', function () {
    $dataSource = DataSource::factory()->create([
        'source_type' => DatabaseSource::class,
        'settings' => [
            'db_driver' => 'sqlsrv',
            'db_host' => 'mssql.example.test',
            'db_database' => 'erp',
            'db_username' => 'importer',
            'db_trust_server_certificate' => true,
        ],
    ]);

    app(DataSourceFactory::class)->make($dataSource);

    $connection = config("database.connections.import_{$dataSource->id}");

    expect($connection['port'])->toBe(1433)
        ->and($connection['encrypt'])->toBe('yes')
        ->and($connection['trust_server_certificate'])->toBe('yes');
});
