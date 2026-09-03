<?php

namespace App\Models;

use App\Services\CacheService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Carrier extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // Clear carrier services cache when carrier changes (affects active services)
        static::saved(fn () => app(CacheService::class)->clearCarrierServicesCache());
        static::deleted(fn () => app(CacheService::class)->clearCarrierServicesCache());
    }

    protected $fillable = [
        'name',
        'pickup_cutoff_hour',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'pickup_cutoff_hour' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CarrierService, $this>
     */
    public function carrierServices(): HasMany
    {
        return $this->hasMany(CarrierService::class);
    }

    /**
     * @return HasMany<CarrierAccount, $this>
     */
    public function carrierAccounts(): HasMany
    {
        return $this->hasMany(CarrierAccount::class);
    }

    /**
     * Raw carrier names that normalize to this carrier identity.
     *
     * @return HasMany<CarrierAlias, $this>
     */
    public function carrierAliases(): HasMany
    {
        return $this->hasMany(CarrierAlias::class);
    }

    /**
     * Packages that permanently snapshot this carrier identity.
     *
     * @return HasMany<Package, $this>
     */
    public function normalizedPackages(): HasMany
    {
        return $this->hasMany(Package::class, 'normalized_carrier_id');
    }

    /**
     * @return BelongsToMany<Location, $this>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'carrier_location')
            ->withPivot('pickup_days', 'last_end_of_day_at')
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Return the public URL for this carrier's logo, or null if no logo file exists.
     */
    public function logoUrl(): ?string
    {
        return static::logoUrlForName($this->name);
    }

    /**
     * Return the public URL for a carrier logo by name, or null if no logo file exists.
     */
    public static function logoUrlForName(string $name): ?string
    {
        $slug = strtolower(str_replace(' ', '-', $name));
        $path = public_path("images/{$slug}-logo.svg");

        return file_exists($path) ? asset("images/{$slug}-logo.svg") : null;
    }
}
