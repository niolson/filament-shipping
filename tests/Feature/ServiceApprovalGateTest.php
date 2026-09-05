<?php

use App\Enums\AuditAction;
use App\Enums\SourceEnvironment;
use App\Exceptions\UnnormalizedServiceApprovalException;
use App\Models\AuditLog;
use App\Models\CarrierService;
use App\Models\Client;
use App\Models\ObservedService;
use App\Models\ServiceApproval;
use App\Models\User;
use App\Services\PostageSources\ObservedServiceMapper;
use App\Services\PostageSources\ServiceApprovalGate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->gate = app(ServiceApprovalGate::class);

    // Every grant names an author — the gate requires one, so the tests carry
    // one rather than papering over the invariant with a nullable default.
    $this->approver = User::factory()->create();
});

function approvalQuestion(ObservedService $observation, ?int $clientId, ?SourceEnvironment $environment = null): array
{
    return [
        $observation->source,
        $environment ?? $observation->environment,
        $observation->external_carrier_id,
        $observation->external_service_id,
        $clientId,
    ];
}

it('denies a service nobody has approved', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    expect($this->gate->approved(...approvalQuestion($observation, $client->id)))->toBeFalse();
});

it('approves a mapped service for one client', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();
    $approver = User::factory()->create();

    $approval = $this->gate->grant($observation, $client, $approver);

    expect($this->gate->approved(...approvalQuestion($observation, $client->id)))->toBeTrue()
        ->and($approval->approved_by_user_id)->toBe($approver->id)
        ->and($approval->approved_at)->not->toBeNull();
});

it('refuses to approve a service that has not been normalized', function (): void {
    // ADR-0003 decision 2: promotion is a precondition of approval, not a
    // parallel track. Approving something nobody has named would authorize
    // spending on a service no report could describe.
    $observation = ObservedService::factory()->create();
    $client = Client::factory()->create();

    expect(fn () => $this->gate->grant($observation, $client, $this->approver))
        ->toThrow(UnnormalizedServiceApprovalException::class);

    expect(ServiceApproval::count())->toBe(0);
});

it('keeps one client out of another client approval', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $approved = Client::factory()->create();
    $other = Client::factory()->create();

    $this->gate->grant($observation, $approved, $this->approver);

    expect($this->gate->approved(...approvalQuestion($observation, $approved->id)))->toBeTrue()
        ->and($this->gate->approved(...approvalQuestion($observation, $other->id)))->toBeFalse();
});

it('does not let a sandbox approval authorize a production purchase', function (): void {
    // The sandbox run returned only AMZN_US / std-us-swa-mfn, while production
    // for the same channel returned OnTrac, UPS and USPS and no Amazon Shipping
    // at all. An approval earned against sandbox identifiers is evidence about
    // nothing that costs money.
    $carrierService = CarrierService::factory()->create();
    $client = Client::factory()->create();

    $sandbox = ObservedService::factory()->mapped($carrierService)->create([
        'environment' => SourceEnvironment::Sandbox,
    ]);

    $this->gate->grant($sandbox, $client, $this->approver);

    expect($this->gate->approved(...approvalQuestion($sandbox, $client->id)))->toBeTrue()
        ->and($this->gate->approved(...approvalQuestion($sandbox, $client->id, SourceEnvironment::Production)))->toBeFalse();
});

it('does not let a production approval authorize a sandbox purchase', function (): void {
    $observation = ObservedService::factory()->mapped()->create([
        'environment' => SourceEnvironment::Production,
    ]);
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);

    expect($this->gate->approved(...approvalQuestion($observation, $client->id, SourceEnvironment::Sandbox)))->toBeFalse();
});

it('scopes approval to one postage source', function (): void {
    $observation = ObservedService::factory()->mapped()->create(['source' => 'amazon']);
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);

    // The same carrier and service name, offered by something else, is a
    // different purchase on a different account.
    expect($this->gate->approved(
        'shopify',
        $observation->environment,
        $observation->external_carrier_id,
        $observation->external_service_id,
        $client->id,
    ))->toBeFalse();
});

it('denies when the caller cannot say whose money it is', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $this->gate->grant($observation, Client::factory()->create(), $this->approver);

    expect($this->gate->approved(...approvalQuestion($observation, null)))->toBeFalse()
        ->and($this->gate->approvedServiceKeys($observation->source, $observation->environment, null))->toBeEmpty();
});

it('takes effect the moment approval is revoked, with nothing re-quoted', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);
    expect($this->gate->approved(...approvalQuestion($observation, $client->id)))->toBeTrue();

    // No cache to expire between these two lines, which is the point: the
    // question is asked at selection time, so revoking stops the next
    // selection rather than the next hour's.
    expect($this->gate->revoke($observation, $client))->toBe(1)
        ->and($this->gate->approved(...approvalQuestion($observation, $client->id)))->toBeFalse();
});

it('grants again after a revoke without duplicating the row', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);
    $this->gate->revoke($observation, $client);
    $this->gate->grant($observation, $client, $this->approver);

    expect(ServiceApproval::count())->toBe(1);
});

it('lists approved services for one client and source in a single question', function (): void {
    $client = Client::factory()->create();
    $other = Client::factory()->create();

    $ground = ObservedService::factory()->mapped()->create([
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_GROUND_ADVANTAGE',
    ]);
    $express = ObservedService::factory()->mapped()->create([
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_PRIORITY_MAIL_EXPRESS',
    ]);

    $this->gate->grant($ground, $client, $this->approver);
    $this->gate->grant($express, $other, $this->approver);

    expect($this->gate->approvedServiceKeys('amazon', SourceEnvironment::Production, $client->id)->all())
        ->toBe([ObservedService::serviceKey('amazon', 'USPS', 'USPS_GROUND_ADVANTAGE')]);
});

it('sets the approved clients to exactly what was submitted', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    [$kept, $withdrawn, $added] = Client::factory()->count(3)->create();

    $this->gate->grant($observation, $kept, $this->approver);
    $this->gate->grant($observation, $withdrawn, $this->approver);

    $result = $this->gate->syncClients($observation, [$kept->id, $added->id], $this->approver);

    expect($result)->toBe(['granted' => 1, 'revoked' => 1])
        ->and($this->gate->approvedClientIds($observation)->sort()->values()->all())
        ->toBe(collect([$kept->id, $added->id])->sort()->values()->all());
});

it('withdraws every approval when the service is unmapped', function (): void {
    // Normalization is the precondition, so withdrawing the name withdraws the
    // permission rather than leaving a row that quietly means nothing.
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);

    $result = app(ObservedServiceMapper::class)->unmap($observation);

    expect($result['approvals'])->toBe(1)
        ->and(ServiceApproval::count())->toBe(0)
        ->and($this->gate->approved(...approvalQuestion($observation, $client->id)))->toBeFalse();
});

it('withdraws approvals in every world the unmapping reaches', function (): void {
    // A mapping spans environments; a revocation narrower than the unmapping
    // that triggered it would leave a sandbox approval on a service that is no
    // longer named anything.
    $carrierService = CarrierService::factory()->create();
    $client = Client::factory()->create();

    $production = ObservedService::factory()->mapped($carrierService)->create([
        'environment' => SourceEnvironment::Production,
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_GROUND_ADVANTAGE',
    ]);
    $sandbox = ObservedService::factory()->mapped($carrierService)->create([
        'environment' => SourceEnvironment::Sandbox,
        'external_carrier_id' => 'USPS',
        'external_service_id' => 'USPS_GROUND_ADVANTAGE',
    ]);

    $this->gate->grant($production, $client, $this->approver);
    $this->gate->grant($sandbox, $client, $this->approver);

    app(ObservedServiceMapper::class)->unmap($production);

    expect(ServiceApproval::count())->toBe(0)
        ->and($sandbox->fresh()->isMapped())->toBeFalse();
});

it('keeps approval through a re-mapping, which changes the name and not the purchase', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);

    app(ObservedServiceMapper::class)->map($observation, CarrierService::factory()->create());

    expect($this->gate->approved(...approvalQuestion($observation, $client->id)))->toBeTrue();
});

it('will not let a client be deleted out from under its approvals', function (): void {
    // A database-level cascade would withdraw permission to spend money without
    // loading a model, so `AuditableObserver` would never hear about it. Every
    // other client-scoped table restricts for its own reasons; this one
    // restricts so that withdrawal always goes through the audited path.
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);

    expect(fn () => $client->delete())->toThrow(QueryException::class);

    $this->gate->revoke($observation, $client);

    $client->delete();

    expect(Client::whereKey($client->id)->exists())->toBeFalse();
});

it('records who authorized every approval it writes', function (): void {
    // A standing permission to spend somebody's money unattended, with no
    // answer to "on whose authority", is the row the two attribution columns
    // exist to prevent. The gate requires an approver, so there is no call that
    // produces one — and the database says so too: `approved_by_name` is NOT
    // NULL while the foreign key beside it is nullable.
    $observation = ObservedService::factory()->mapped()->create();

    $this->gate->syncClients($observation, [Client::factory()->create()->id], $this->approver);

    expect(ServiceApproval::sole()->approved_by_name)->toBe($this->approver->name);
});

it('builds approvals whose two attribution columns agree', function (): void {
    // A fixture that named one author in the foreign key and another in the
    // snapshot would be a row the gate cannot write, and would make any test
    // reading provenance prove nothing.
    $approval = ServiceApproval::factory()->create();

    expect($approval->approved_by_name)->toBe($approval->approvedBy->name);

    $someone = User::factory()->create(['name' => 'Wren Okafor']);
    $named = ServiceApproval::factory()->approvedBy($someone)->create();

    expect($named->approved_by_user_id)->toBe($someone->id)
        ->and($named->approved_by_name)->toBe('Wren Okafor');

    $former = ServiceApproval::factory()->formerApprover()->create();

    expect($former->approved_by_user_id)->toBeNull()
        ->and($former->approved_by_name)->toBe('Dana Reyes');
});

it('still says who authorized a spend after their account is gone', function (): void {
    // The foreign key nulls on delete, which is why the name is snapshotted
    // beside it: an approval outlives the audit log's retention, and it should
    // outlive a departed administrator's account too.
    $observation = ObservedService::factory()->mapped()->create();
    $approver = User::factory()->create(['name' => 'Dana Reyes']);

    $approval = $this->gate->grant($observation, Client::factory()->create(), $approver);

    $approver->delete();

    expect($approval->fresh()->approved_by_user_id)->toBeNull()
        ->and($approval->fresh()->approved_by_name)->toBe('Dana Reyes');
});

it('records the withdrawal of an approval, not only the granting of one', function (): void {
    // Permission to spend money unattended is exactly the thing an audit log is
    // asked about afterwards, and a mass delete would have carried every grant
    // and no withdrawal: `->delete()` on a query never loads a model, so
    // `AuditableObserver` never hears about it.
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);
    $this->gate->revoke($observation, $client);

    $entries = AuditLog::where('auditable_type', ServiceApproval::class)->pluck('action');

    expect($entries->all())->toBe([AuditAction::ModelCreated, AuditAction::ModelDeleted]);
});

it('records a withdrawal made by unmapping', function (): void {
    $observation = ObservedService::factory()->mapped()->create();

    $this->gate->grant($observation, Client::factory()->create(), $this->approver);
    app(ObservedServiceMapper::class)->unmap($observation);

    expect(AuditLog::where('auditable_type', ServiceApproval::class)
        ->where('action', AuditAction::ModelDeleted)
        ->count())->toBe(1);
});

it('records a withdrawal made by unticking a client', function (): void {
    $observation = ObservedService::factory()->mapped()->create();
    $client = Client::factory()->create();

    $this->gate->grant($observation, $client, $this->approver);
    $this->gate->syncClients($observation, [], $this->approver);

    expect(AuditLog::where('auditable_type', ServiceApproval::class)
        ->where('action', AuditAction::ModelDeleted)
        ->count())->toBe(1);
});

it('withdraws approvals and clears the mapping in one transaction', function (): void {
    // Apart, a mapping update that failed after the approvals were already
    // committed would leave the service mapped and silently de-approved.
    $observation = ObservedService::factory()->mapped()->create();
    $this->gate->grant($observation, Client::factory()->create(), $this->approver);

    $outer = DB::transactionLevel();
    $duringDelete = null;

    ServiceApproval::deleted(function () use (&$duringDelete): void {
        $duringDelete ??= DB::transactionLevel();
    });

    app(ObservedServiceMapper::class)->unmap($observation);

    expect($duringDelete)->toBeGreaterThan($outer);
});
