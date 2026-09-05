<?php

namespace App\Models;

use App\Enums\SourceEnvironment;
use App\Services\PostageSources\ServiceApprovalGate;
use Database\Factories\ServiceApprovalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One client's permission for automation to spend money on one discovered
 * service, in one world.
 *
 * ADR-0003 decision 3, and the third of the three concepts decision 2 keeps
 * apart: {@see ObservedService} is what a source said exists, its
 * `carrier_service_id` is what we decided to call it, and this is whether an
 * unattended path may buy it. The first two are statements of fact and naming;
 * only this one spends money, which is why it alone is scoped to a client and
 * to an environment.
 *
 * Absence is denial. Nothing here has to be revoked for the safe answer, and
 * an install that never approves anything behaves exactly as it did before
 * discovery existed: automation reaches only authored, seeded services.
 *
 * Grant and revoke go through {@see ServiceApprovalGate}, which is also the
 * only thing that answers the question. Writing a row directly skips the
 * check that the service was normalized first.
 *
 * @property string $source
 * @property SourceEnvironment $environment
 * @property string $external_carrier_id
 * @property string $external_service_id
 * @property int $client_id
 * @property int|null $approved_by_user_id
 * @property string $approved_by_name
 * @property Carbon $approved_at
 */
class ServiceApproval extends Model
{
    /** @use HasFactory<ServiceApprovalFactory> */
    use HasFactory;

    protected $fillable = [
        'source',
        'environment',
        'external_carrier_id',
        'external_service_id',
        'client_id',
        'approved_by_user_id',
        'approved_by_name',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'environment' => SourceEnvironment::class,
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * The exact scope an approval covers: this service, from this source, in
     * this world.
     *
     * One axis narrower than {@see ObservedService::scopeSameService()}, which
     * a mapping uses, and the extra axis is `environment` — deliberately. A
     * name is a name in both worlds; a permission to spend is not. Marketplace
     * is absent from both.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForService(
        Builder $query,
        string $source,
        SourceEnvironment $environment,
        string $externalCarrierId,
        string $externalServiceId,
    ): void {
        $query->where('source', $source)
            ->where('environment', $environment)
            ->where('external_carrier_id', $externalCarrierId)
            ->where('external_service_id', $externalServiceId);
    }
}
