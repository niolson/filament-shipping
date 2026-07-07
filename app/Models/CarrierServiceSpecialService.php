<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $carrier_service_id
 * @property int $special_service_id
 * @property array<int, string>|null $restricted_countries
 */
class CarrierServiceSpecialService extends Pivot
{
    protected $table = 'carrier_service_special_service';

    public $incrementing = true;

    protected $fillable = [
        'carrier_service_id',
        'special_service_id',
        'restricted_countries',
    ];

    protected function casts(): array
    {
        return [
            'restricted_countries' => 'array',
        ];
    }
}
