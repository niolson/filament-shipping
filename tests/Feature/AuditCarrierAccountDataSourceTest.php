<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\User;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('audits CarrierAccount create, update and delete without leaking the secret', function (): void {
    $account = CarrierAccount::factory()->create([
        'secret_credentials' => ['client_secret' => 'SUPER-SECRET-VALUE'],
    ]);

    $created = AuditLog::where('auditable_type', $account->getMorphClass())
        ->where('auditable_id', $account->id)
        ->where('action', AuditAction::ModelCreated)
        ->firstOrFail();

    expect($created->new_values['secret_credentials'])->toBe('[encrypted]');
    expect(json_encode($created->new_values))->not->toContain('SUPER-SECRET-VALUE');

    $account->update(['name' => 'Renamed Account']);
    expect(AuditLog::where('auditable_id', $account->id)->where('action', AuditAction::ModelUpdated)->exists())->toBeTrue();

    $account->delete();
    expect(AuditLog::where('auditable_id', $account->id)->where('action', AuditAction::ModelDeleted)->exists())->toBeTrue();
});

it('audits DataSource create and masks the encrypted secret_settings', function (): void {
    $source = DataSource::factory()->create([
        'driver' => DatabaseSource::class,
        'secret_settings' => ['db_password' => 'DB-PASSWORD-SECRET'],
    ]);

    $created = AuditLog::where('auditable_type', $source->getMorphClass())
        ->where('auditable_id', $source->id)
        ->where('action', AuditAction::ModelCreated)
        ->firstOrFail();

    expect($created->new_values['secret_settings'])->toBe('[encrypted]');
    expect(json_encode($created->new_values))->not->toContain('DB-PASSWORD-SECRET');
});
