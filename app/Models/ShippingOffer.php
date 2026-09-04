<?php

namespace App\Models;

use App\Enums\PostageSource;
use App\Enums\SourceEnvironment;
use Database\Factories\ShippingOfferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One ephemeral, package-specific quote, and the authority to buy it.
 *
 * ADR-0002 decision 4. Everything a purchase needs that a carrier and a service
 * name cannot reconstruct lives here — the source instance, the opaque tokens,
 * the environment, the expiry — so that the browser can hold {@see $public_id}
 * alone and still buy exactly the thing the operator looked at.
 *
 * Consumption is one-way. An offer whose purchase failed is not returned to the
 * pool: re-quoting is cheap and correct, while re-spending an identifier the
 * source may already have honoured is neither.
 *
 * @property string $public_id
 * @property int $package_id
 * @property PostageSource $postage_source
 * @property SourceEnvironment $environment
 * @property string $carrier
 * @property array<string, mixed>|null $rate_metadata
 * @property array<string, mixed>|null $purchase_context
 * @property string|null $purchase_reference
 * @property string|null $purchase_failure_reason
 * @property Carbon|null $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $purchase_failed_at
 */
class ShippingOffer extends Model
{
    /** @use HasFactory<ShippingOfferFactory> */
    use HasFactory;

    protected $fillable = [
        'package_id',
        'postage_source',
        'carrier_account_id',
        'postage_data_source_id',
        'carrier',
        'service_code',
        'service_name',
        'price',
        'currency',
        'rate_metadata',
        'purchase_context',
        'environment',
        'marketplace',
        'expires_at',
    ];

    /**
     * The purchase context never belongs in an array cast of this model — it is
     * the one field that can spend money, and `toArray()` is how state reaches
     * Livewire.
     *
     * @var list<string>
     */
    protected $hidden = [
        'purchase_context',
    ];

    protected function casts(): array
    {
        return [
            'postage_source' => PostageSource::class,
            'environment' => SourceEnvironment::class,
            'price' => 'decimal:2',
            'rate_metadata' => 'array',
            'purchase_context' => 'encrypted:array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'purchase_failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $offer): void {
            $offer->public_id ??= (string) Str::ulid();
        });
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return BelongsTo<CarrierAccount, $this>
     */
    public function carrierAccount(): BelongsTo
    {
        return $this->belongsTo(CarrierAccount::class);
    }

    /**
     * @return BelongsTo<DataSource, $this>
     */
    public function postageDataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'postage_data_source_id');
    }

    /**
     * An offer with no published window has not expired — the absence of an
     * expiry is not an expiry of zero.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /**
     * Spent, with the source's answer unknown.
     *
     * The recovery case, and the reason a declined purchase is tracked apart
     * from a failed one. A source that answered "no" resolves the offer and
     * leaves nothing to recover. A timeout, a dropped connection or a crash
     * between the request and the response leaves a purchase that may have
     * succeeded upstream and failed here — indistinguishable from one that
     * never happened except by asking the source under this offer's identifier.
     *
     * Nothing may be spent on the package while one of these stands, and
     * `data:purge` never deletes one.
     */
    public function isAwaitingPurchaseConfirmation(): bool
    {
        return $this->isConsumed()
            && $this->purchase_reference === null
            && $this->purchase_failed_at === null;
    }

    /**
     * Spent and settled, one way or the other.
     */
    public function isResolved(): bool
    {
        return $this->purchase_reference !== null || $this->purchase_failed_at !== null;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeUnconsumed(Builder $query): void
    {
        $query->whereNull('consumed_at');
    }
}
