<?php

namespace App\Models;

use App\Enums\SourceEnvironment;
use Database\Factories\ObservedServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A durable service identity a postage source has reported at least once.
 *
 * ADR-0003 decision 2, the observation half. This is what Amazon says exists;
 * {@see CarrierService} is what we have decided to call a service. Keeping them
 * apart is what lets an offer for OnTrac — a carrier we hold no row for, no
 * account with, and no adapter — be recorded at all.
 *
 * Being observed says nothing about whether automation may spend money on it.
 * That is approval, and it is a separate concept again (ADR-0003 decision 3).
 *
 * @property string $source
 * @property SourceEnvironment $environment
 * @property string $marketplace
 * @property string $external_carrier_id
 * @property string|null $external_carrier_name
 * @property string $external_service_id
 * @property string|null $external_service_name
 * @property int|null $carrier_service_id
 * @property int $observation_count
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $last_eligible_at
 */
class ObservedService extends Model
{
    /** @use HasFactory<ObservedServiceFactory> */
    use HasFactory;

    protected $fillable = [
        'source',
        'environment',
        'marketplace',
        'external_carrier_id',
        'external_carrier_name',
        'external_service_id',
        'external_service_name',
        'carrier_service_id',
        'first_seen_at',
        'last_seen_at',
        'last_eligible_at',
        'observation_count',
    ];

    protected function casts(): array
    {
        return [
            'environment' => SourceEnvironment::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_eligible_at' => 'datetime',
            'observation_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CarrierService, $this>
     */
    public function carrierService(): BelongsTo
    {
        return $this->belongsTo(CarrierService::class);
    }

    public function isMapped(): bool
    {
        return $this->carrier_service_id !== null;
    }

    /**
     * Whether this identity has ever been offered as buyable, as opposed to
     * only ever appearing in an `ineligibleRates` catalog.
     */
    public function hasBeenEligible(): bool
    {
        return $this->last_eligible_at !== null;
    }

    /**
     * How the source names it, for a human deciding what to map it to.
     */
    public function displayName(): string
    {
        $carrier = $this->external_carrier_name ?? $this->external_carrier_id;
        $service = $this->external_service_name ?? $this->external_service_id;

        return "{$carrier} — {$service}";
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeUnmapped(Builder $query): void
    {
        $query->whereNull('carrier_service_id');
    }
}
