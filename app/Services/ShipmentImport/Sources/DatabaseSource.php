<?php

namespace App\Services\ShipmentImport\Sources;

use App\Contracts\DataSourceInterface;
use App\Contracts\ExportDestinationInterface;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Services\ShipmentImport\FieldMapper;
use App\Services\ShipmentImport\RawSqlGuard;
use App\Services\SshTunnel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class DatabaseSource implements DataSourceInterface, ExportDestinationInterface
{
    private array $config;

    private FieldMapper $fieldMapper;

    private ?SshTunnel $tunnel = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->fieldMapper = new FieldMapper($this->config['field_mapping'] ?? []);
    }

    public function validateConfiguration(): void
    {
        $connection = $this->config['connection'] ?? null;

        if (! $connection) {
            throw new InvalidArgumentException('Database connection is not configured.');
        }

        $this->openTunnelIfConfigured();

        // Test connection
        try {
            DB::connection($connection)->getPdo();
        } catch (\Exception $e) {
            $this->closeTunnel();
            logger()->error('Import database connection failed', [
                'connection' => $connection,
                'error' => $e->getMessage(),
            ]);

            throw new InvalidArgumentException('Cannot connect to import database. Check connection settings.');
        }
    }

    public function fetchShipments(): Collection
    {
        $connection = $this->config['connection'];

        // Use custom query if provided
        if (! empty($this->config['shipments_query'])) {
            $query = $this->normalizeQuery($this->config['shipments_query']);
            RawSqlGuard::assertStatementType($query, RawSqlGuard::READ, 'custom shipments query');
            $results = $this->executeLogged(
                'fetch_shipments',
                $query,
                [],
                fn () => DB::connection($connection)->select($query),
            );
        } else {
            $query = DB::connection($connection)
                ->table($this->config['shipments_table']);

            // Apply filters
            foreach ($this->config['filters'] ?? [] as $field => $values) {
                if (is_array($values)) {
                    $query->whereIn($field, $values);
                } else {
                    $query->where($field, $values);
                }
            }

            $results = $query->get();
        }

        $clientColumn = $this->config['client_column'] ?? null;

        // Map external fields to internal fields
        return collect($results)->map(function ($row) use ($clientColumn) {
            $mapped = $this->fieldMapper->mapShipment($row);

            // Carry over the raw client column value so the importer can resolve
            // the correct Client for per-row multi-client database imports.
            if ($clientColumn) {
                $rawRow = (array) $row;
                $mapped['_client_column_value'] = $rawRow[$clientColumn] ?? null;
            }

            return $mapped;
        });
    }

    public function fetchShipmentItems(string $sourceRecordId): Collection
    {
        $connection = $this->config['connection'];

        // Use custom query if provided
        if (! empty($this->config['shipment_items_query'])) {
            $query = $this->normalizeQuery($this->config['shipment_items_query']);
            RawSqlGuard::assertStatementType($query, RawSqlGuard::READ, 'custom items query');
            $results = $this->executeLogged(
                'fetch_shipment_items',
                $query,
                ['shipment_reference'],
                fn () => DB::connection($connection)->select($query, ['shipment_reference' => $sourceRecordId]),
                ['shipment_reference' => $sourceRecordId],
            );
        } else {
            // Default: lookup by shipment_id field matching the reference
            $results = DB::connection($connection)
                ->table($this->config['shipment_items_table'])
                ->where('shipment_id', $sourceRecordId)
                ->get();
        }

        return collect($results)->map(function ($row) {
            return $this->fieldMapper->mapShipmentItem($row);
        });
    }

    public function getFieldMapping(): array
    {
        return $this->config['field_mapping'] ?? [];
    }

    public function markExported(string $sourceRecordId): bool
    {
        $markExported = $this->config['mark_exported'] ?? [];

        if (empty($markExported['enabled']) || empty($markExported['query'])) {
            return false;
        }

        $query = $this->normalizeQuery($markExported['query']);
        RawSqlGuard::assertStatementType($query, RawSqlGuard::MARK_EXPORTED, 'mark-exported query');
        $this->executeLogged(
            'mark_exported',
            $query,
            ['shipment_reference'],
            fn (): int => $this->runCappedStatement(
                $this->config['connection'],
                $query,
                ['shipment_reference' => $sourceRecordId],
            ),
            ['shipment_reference' => $sourceRecordId],
            fn (int $affected): array => ['affected_rows' => $affected],
        );

        return true;
    }

    public function getDestinationName(): string
    {
        return 'database';
    }

    public function exportPackage(array $data): void
    {
        $exportConfig = $this->config['export'] ?? [];

        if (empty($exportConfig['query'])) {
            throw new InvalidArgumentException('Export query is not configured for database source.');
        }

        $query = $this->normalizeQuery($exportConfig['query']);
        RawSqlGuard::assertStatementType($query, RawSqlGuard::EXPORT, 'export query');

        // Only pass parameters that the query actually references,
        // so the field_mapping can be a superset of what the query needs.
        preg_match_all('/:(\w+)/', $query, $matches);
        $queryParams = array_flip($matches[1]);
        $filteredData = array_intersect_key($data, $queryParams);

        // Log the bound parameter keys only — values may contain shipment PII.
        $this->executeLogged(
            'export_package',
            $query,
            array_keys($filteredData),
            fn (): int => $this->runCappedStatement($this->config['connection'], $query, $filteredData),
            [],
            fn (int $affected): array => ['affected_rows' => $affected],
        );
    }

    public function validateExportConfiguration(): void
    {
        $exportConfig = $this->config['export'] ?? [];

        if (empty($exportConfig['enabled'])) {
            throw new InvalidArgumentException('Export is not enabled for database source.');
        }

        if (empty($exportConfig['query'])) {
            throw new InvalidArgumentException('Export query is not configured for database source.');
        }

        // Test connection
        $connection = $this->config['connection'] ?? null;

        if (! $connection) {
            throw new InvalidArgumentException('Database connection is not configured.');
        }

        $this->openTunnelIfConfigured();

        try {
            DB::connection($connection)->getPdo();
        } catch (\Exception $e) {
            $this->closeTunnel();
            logger()->error('Export database connection failed', [
                'connection' => $connection,
                'error' => $e->getMessage(),
            ]);

            throw new InvalidArgumentException('Cannot connect to export database. Check connection settings.');
        }
    }

    /**
     * Run an admin-authored raw SQL query and record its execution outcome, so
     * scheduled import/export runs leave an accurate trace of what actually ran
     * and whether it succeeded. The query is logged only after it resolves — a
     * failure is recorded with status `failed` and the exception is re-thrown, so
     * a failed query never leaves an audit record claiming it executed.
     *
     * @template TResult
     *
     * @param  list<string>  $parameterKeys
     * @param  callable(): TResult  $run
     * @param  array<string, mixed>  $extra
     * @param  (callable(TResult): array<string, mixed>)|null  $successMetadata  Derive extra
     *                                                                           audit metadata (e.g. affected-row count) from the result on success.
     * @return TResult
     */
    private function executeLogged(string $operation, string $query, array $parameterKeys, callable $run, array $extra = [], ?callable $successMetadata = null)
    {
        try {
            $result = $run();
        } catch (\Throwable $e) {
            $this->logQueryExecution($operation, $query, $parameterKeys, 'failed', $extra);
            throw $e;
        }

        if ($successMetadata !== null) {
            $extra = array_merge($extra, $successMetadata($result));
        }

        $this->logQueryExecution($operation, $query, $parameterKeys, 'success', $extra);

        return $result;
    }

    /**
     * Run a single-record write inside a transaction, rolling back (and throwing)
     * if it affects more rows than the configured cap. mark-exported and export
     * both run once per record, so a query that touches many rows means a missing
     * or too-broad WHERE — this bounds the blast radius of such a misconfiguration
     * even though the statement type itself is allowed. See security review issue 07.
     */
    private function runCappedStatement(string $connection, string $query, array $bindings): int
    {
        $cap = $this->maxAffectedRows();

        return DB::connection($connection)->transaction(function () use ($connection, $query, $bindings, $cap): int {
            $affected = DB::connection($connection)->affectingStatement($query, $bindings);

            if ($affected > $cap) {
                throw new RuntimeException(
                    "Query affected {$affected} rows, exceeding the configured limit of {$cap} — rolled back."
                );
            }

            return $affected;
        });
    }

    /**
     * Per-source cap on how many rows a single mark-exported/export write may
     * affect. Defaults to 1 (these are per-record operations); raise it only for a
     * source whose schema legitimately stores multiple rows per shipment.
     */
    private function maxAffectedRows(): int
    {
        return max(1, (int) ($this->config['max_affected_rows'] ?? 1));
    }

    /**
     * Record a raw-SQL execution to the audit log. Captures the query's identity
     * (hash + truncated preview), bound parameter keys, and success/failure
     * status — never secret values or PII. See security review issue 18.
     *
     * @param  list<string>  $parameterKeys
     * @param  array<string, mixed>  $extra
     */
    private function logQueryExecution(string $operation, string $query, array $parameterKeys, string $status, array $extra = []): void
    {
        try {
            AuditLog::record(
                AuditAction::DataSourceQueryExecuted,
                metadata: array_merge([
                    'operation' => $operation,
                    'status' => $status,
                    'connection' => $this->config['connection'] ?? null,
                    'data_source_id' => $this->config['data_source_id'] ?? null,
                    'query_hash' => hash('sha256', $query),
                    'query_preview' => Str::limit($query, 300),
                    'parameters' => $parameterKeys,
                ], $extra),
            );
        } catch (\Throwable $e) {
            // Audit logging must never break an import/export run.
            logger()->warning('Failed to record DataSource query audit log', [
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Replace non-breaking spaces and other Unicode whitespace variants with
     * ASCII spaces so the query executes correctly on MySQL/MariaDB.
     */
    private function normalizeQuery(string $query): string
    {
        return str_replace("\u{00A0}", ' ', $query);
    }

    /**
     * Open an SSH tunnel if configured and override the DB connection host/port.
     */
    private function openTunnelIfConfigured(): void
    {
        if ($this->tunnel !== null) {
            return;
        }

        $sshConfig = $this->config['ssh'] ?? [];

        if (empty($sshConfig['enabled'])) {
            return;
        }

        foreach (['host', 'user', 'key', 'host_key'] as $required) {
            if (empty($sshConfig[$required])) {
                throw new InvalidArgumentException("SSH tunnel config missing: ssh.{$required}");
            }
        }

        $connection = $this->config['connection'];
        $dbConfig = config("database.connections.{$connection}");

        $this->tunnel = SshTunnel::fromConfig([
            'ssh_host' => $sshConfig['host'],
            'ssh_port' => (int) ($sshConfig['port'] ?? 22),
            'ssh_user' => $sshConfig['user'],
            'ssh_key' => $sshConfig['key'],
            'remote_host' => $sshConfig['remote_host'] ?? $dbConfig['host'],
            'remote_port' => (int) ($sshConfig['remote_port'] ?? $dbConfig['port']),
            'known_hosts_entry' => $sshConfig['host_key'],
            'known_hosts_file' => $sshConfig['known_hosts_file'] ?? storage_path('app/private/ssh/import_known_hosts'),
        ]);

        $localPort = $this->tunnel->open();

        // Point the DB connection through the tunnel
        config([
            "database.connections.{$connection}.host" => '127.0.0.1',
            "database.connections.{$connection}.port" => $localPort,
        ]);

        // Purge any cached connection so it reconnects through the tunnel
        DB::purge($connection);
    }

    /**
     * Close the SSH tunnel and restore the DB connection config.
     */
    public function closeTunnel(): void
    {
        if ($this->tunnel === null) {
            return;
        }

        $this->tunnel->close();
        $this->tunnel = null;

        // Purge the tunneled connection
        $connection = $this->config['connection'] ?? null;
        if ($connection) {
            DB::purge($connection);
        }
    }

    public function __destruct()
    {
        $this->closeTunnel();
    }
}
