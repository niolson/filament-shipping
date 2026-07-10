<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Build a DatabaseSource bound to the app's own test connection. The queries used
 * here are harmless no-ops (WHERE 1 = 0) — we only care that execution is logged.
 */
function databaseSourceWith(array $config): DatabaseSource
{
    return new DatabaseSource(array_merge([
        'connection' => config('database.default'),
        'data_source_id' => 42,
    ], $config));
}

it('audits a mark_exported query execution with its identity and reference', function (): void {
    $query = 'UPDATE audit_logs SET action = action WHERE :shipment_reference IS NOT NULL AND 1 = 0';

    $source = databaseSourceWith([
        'mark_exported' => ['enabled' => true, 'query' => $query],
    ]);

    expect($source->markExported('REF-123'))->toBeTrue();

    $log = AuditLog::where('action', AuditAction::DataSourceQueryExecuted)->firstOrFail();

    expect($log->metadata['operation'])->toBe('mark_exported')
        ->and($log->metadata['status'])->toBe('success')
        ->and($log->metadata['data_source_id'])->toBe(42)
        ->and($log->metadata['query_hash'])->toBe(hash('sha256', $query))
        ->and($log->metadata['parameters'])->toBe(['shipment_reference'])
        ->and($log->metadata['shipment_reference'])->toBe('REF-123');
});

it('records a failed status and rethrows when a query errors', function (): void {
    $query = 'UPDATE this_table_does_not_exist SET x = 1 WHERE :shipment_reference IS NOT NULL';

    $source = databaseSourceWith([
        'mark_exported' => ['enabled' => true, 'query' => $query],
    ]);

    $threw = false;
    try {
        $source->markExported('REF-999');
    } catch (Throwable) {
        $threw = true;
    }
    expect($threw)->toBeTrue();

    $log = AuditLog::where('action', AuditAction::DataSourceQueryExecuted)->firstOrFail();
    expect($log->metadata['status'])->toBe('failed')
        ->and($log->metadata['operation'])->toBe('mark_exported');
});

it('logs only bound parameter keys for exports, never the PII values', function (): void {
    $query = 'UPDATE audit_logs SET action = action WHERE 1 = 0 AND :recipient_name IS NOT NULL';

    $source = databaseSourceWith([
        'export' => ['enabled' => true, 'query' => $query],
    ]);

    $source->exportPackage(['recipient_name' => 'Jane Doe', 'unused' => 'ignored']);

    $log = AuditLog::where('action', AuditAction::DataSourceQueryExecuted)->firstOrFail();

    expect($log->metadata['operation'])->toBe('export_package')
        ->and($log->metadata['parameters'])->toBe(['recipient_name']);

    // The actual recipient value must never land in the audit trail.
    expect(json_encode($log->metadata))->not->toContain('Jane Doe');
});
