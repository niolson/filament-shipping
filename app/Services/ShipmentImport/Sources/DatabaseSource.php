<?php

namespace App\Services\ShipmentImport\Sources;

use App\Contracts\ExportDestinationInterface;
use App\Contracts\ImportSourceInterface;
use App\Services\ShipmentImport\FieldMapper;
use App\Services\SshTunnel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DatabaseSource implements ExportDestinationInterface, ImportSourceInterface
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
            $results = DB::connection($connection)
                ->select($this->config['shipments_query']);
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
            $results = DB::connection($connection)
                ->select($this->config['shipment_items_query'], [
                    'shipment_reference' => $sourceRecordId,
                ]);
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

        DB::connection($this->config['connection'])
            ->statement($markExported['query'], [
                'shipment_reference' => $sourceRecordId,
            ]);

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

        $query = $exportConfig['query'];

        // Only pass parameters that the query actually references,
        // so the field_mapping can be a superset of what the query needs.
        preg_match_all('/:(\w+)/', $query, $matches);
        $queryParams = array_flip($matches[1]);
        $filteredData = array_intersect_key($data, $queryParams);

        DB::connection($this->config['connection'])
            ->statement($query, $filteredData);
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
