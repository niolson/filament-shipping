<?php

namespace App\Models;

use App\Services\CacheService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CarrierService extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn () => app(CacheService::class)->clearCarrierServicesCache());
        static::deleted(fn () => app(CacheService::class)->clearCarrierServicesCache());
    }

    protected $fillable = [
        'carrier_id',
        'service_code',
        'name',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * @return BelongsToMany<ShippingMethod, $this>
     */
    public function shippingMethods(): BelongsToMany
    {
        return $this->belongsToMany(ShippingMethod::class);
    }

    /**
     * Special services this specific carrier service can carry (carrier-capability
     * truth, not business policy). A special service with no rows for any of a
     * carrier's services is unrestricted for that carrier.
     *
     * @return BelongsToMany<SpecialService, $this, CarrierServiceSpecialService>
     */
    public function specialServices(): BelongsToMany
    {
        return $this->belongsToMany(SpecialService::class)
            ->using(CarrierServiceSpecialService::class)
            ->withPivot('restricted_countries')
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeWithActiveCarrier(Builder $query): Builder
    {
        return $query->whereHas('carrier', fn (Builder $q) => $q->active());
    }
}
