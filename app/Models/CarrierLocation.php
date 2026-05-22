<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class CarrierLocation extends Model
{
    protected $table = 'carrier_location';

    protected $fillable = [
        'carrier_id',
        'location_id',
        'pickup_days',
        'last_end_of_day_at',
        'usps_crid',
        'usps_mid',
        'usps_eps_account',
    ];

    protected function casts(): array
    {
        return [
            'pickup_days' => 'array',
            'last_end_of_day_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (CarrierLocation $carrierLocation): void {
            if ($carrierLocation->wasChanged(['usps_crid', 'usps_mid', 'usps_eps_account'])) {
                Cache::forget("usps_payment_authorization_token:{$carrierLocation->location_id}");
            }
        });

        static::deleted(function (CarrierLocation $carrierLocation): void {
            Cache::forget("usps_payment_authorization_token:{$carrierLocation->location_id}");
        });
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
