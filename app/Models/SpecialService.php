<?php

namespace App\Models;

use App\Enums\SpecialServiceScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SpecialService extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'scope',
        'category',
        'requires_value',
        'config_schema',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'scope' => SpecialServiceScope::class,
            'requires_value' => 'boolean',
            'config_schema' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<ShippingMethod, $this>
     */
    public function shippingMethods(): BelongsToMany
    {
        return $this->belongsToMany(ShippingMethod::class)
            ->withPivot(['mode', 'config'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Package, $this>
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'package_special_services')
            ->withPivot(['source', 'source_reference', 'config', 'applied_at'])
            ->withTimestamps();
    }

    /**
     * Carrier services explicitly scoped as able to carry this special service.
     *
     * @return BelongsToMany<CarrierService, $this, CarrierServiceSpecialService>
     */
    public function carrierServices(): BelongsToMany
    {
        return $this->belongsToMany(CarrierService::class)
            ->using(CarrierServiceSpecialService::class)
            ->withPivot('restricted_countries')
            ->withTimestamps();
    }
}
