<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierAccountScope extends Model
{
    use HasFactory;

    protected $fillable = [
        'carrier_account_id',
        'location_id',
        'client_id',
        'rate_shop',
    ];

    protected function casts(): array
    {
        return [
            'rate_shop' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CarrierAccountScope $scope): void {
            // Always derive carrier_id from the account — never trust a caller-supplied value.
            if ($scope->carrier_account_id) {
                $scope->carrier_id = CarrierAccount::find($scope->carrier_account_id)?->carrier_id;
            }
        });
    }

    public function carrierAccount(): BelongsTo
    {
        return $this->belongsTo(CarrierAccount::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withDefault();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withDefault();
    }
}
