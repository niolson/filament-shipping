<?php

namespace App\Services\ShipmentImport;

use App\Contracts\ImportSourceInterface;
use App\Models\ImportSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ImportSourceFactory
{
    public function make(ImportSource $importSource): ImportSourceInterface
    {
        $driver = $importSource->driver;

        if (! $driver || ! class_exists($driver)) {
            throw new InvalidArgumentException(
                "Import source '{$importSource->config_key}' has an invalid driver class: {$driver}"
            );
        }

        $config = array_merge(
            $importSource->settings ?? [],
            $importSource->secret_settings ?? [],
            ['config_key' => $importSource->config_key],
        );

        if ($driver === DatabaseSource::class) {
            $config = $this->buildDatabaseConfig($importSource->config_key, $config);
        }

        return new $driver($config);
    }

    /**
     * Build a fully-structured DatabaseSource config from flat ImportSource settings
     * and register a per-source dynamic DB connection so multiple database sources
     * can run concurrently without overwriting each other's connection config.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildDatabaseConfig(string $configKey, array $settings): array
    {
        $connectionName = 'import_'.$configKey;

        if (isset($settings['db_host'])) {
            config([
                "database.connections.{$connectionName}.driver" => $settings['db_driver'] ?? 'mysql',
                "database.connections.{$connectionName}.host" => $settings['db_host'],
                "database.connections.{$connectionName}.port" => (int) ($settings['db_port'] ?? 3306),
                "database.connections.{$connectionName}.database" => $settings['db_database'] ?? null,
                "database.connections.{$connectionName}.username" => $settings['db_username'] ?? null,
                "database.connections.{$connectionName}.password" => $settings['db_password'] ?? null,
                "database.connections.{$connectionName}.charset" => 'utf8mb4',
                "database.connections.{$connectionName}.collation" => 'utf8mb4_unicode_ci',
                "database.connections.{$connectionName}.prefix" => '',
                "database.connections.{$connectionName}.strict" => true,
            ]);
            DB::purge($connectionName);
        }

        return [
            'from_import_source_record' => true,
            'config_key' => $configKey,
            'connection' => $connectionName,
            'enabled' => true,
            'shipments_table' => $settings['shipments_table'] ?? 'shipments',
            'shipment_items_table' => $settings['shipment_items_table'] ?? 'shipment_items',
            'client_column' => $settings['client_column'] ?? null,
            'shipments_query' => $settings['shipments_query'] ?? null,
            'shipment_items_query' => $settings['shipment_items_query'] ?? null,
            'shipment_items' => ['enabled' => true],
            'filters' => $settings['filters'] ?? [],
            'mark_exported' => [
                'enabled' => (bool) ($settings['mark_exported_enabled'] ?? false),
                'query' => $settings['mark_exported_query'] ?? null,
            ],
            'export' => [
                'enabled' => (bool) ($settings['export_enabled'] ?? false),
                'query' => $settings['export_query'] ?? null,
                'field_mapping' => [
                    'tracking_number' => 'tracking_number',
                    'weight' => 'weight',
                    'height' => 'height',
                    'width' => 'width',
                    'length' => 'length',
                    'cost' => 'cost',
                    'carrier' => 'carrier',
                    'service' => 'service',
                    'shipment_reference' => 'shipment_reference',
                ],
            ],
            'ssh' => [
                'enabled' => (bool) ($settings['ssh_enabled'] ?? false),
                'host' => $settings['ssh_host'] ?? null,
                'port' => (int) ($settings['ssh_port'] ?? 22),
                'user' => $settings['ssh_user'] ?? null,
                'key' => storage_path('app/private/ssh/id_ed25519'),
                'remote_host' => $settings['ssh_remote_host'] ?? null,
                'remote_port' => $settings['ssh_remote_port'] ?? null,
                'host_key' => $settings['ssh_host_key'] ?? null,
                'known_hosts_file' => storage_path('app/private/ssh/import_known_hosts'),
            ],
            'field_mapping' => $settings['field_mapping'] ?? [
                'shipment' => [
                    'id' => 'shipment_reference',
                    'first_name' => 'first_name',
                    'last_name' => 'last_name',
                    'company' => 'company',
                    'address1' => 'address1',
                    'address2' => 'address2',
                    'city' => 'city',
                    'state' => 'state_or_province',
                    'zip' => 'postal_code',
                    'country' => 'country',
                    'phone' => 'phone',
                    'email' => 'email',
                    'value' => 'value',
                    'shipping_method' => 'shipping_method_id',
                    'channel' => 'channel_id',
                ],
                'shipment_item' => [
                    'sku' => 'sku',
                    'name' => 'name',
                    'description' => 'description',
                    'barcode' => 'barcode',
                    'quantity' => 'quantity',
                    'weight' => 'weight',
                    'value' => 'value',
                    'transparency' => 'transparency',
                ],
            ],
        ];
    }
}
