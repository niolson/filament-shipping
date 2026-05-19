<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'is_default',
        'active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Client $client): void {
            if ($client->is_default && $client->isDirty('is_default')) {
                static::where('id', '!=', $client->id ?? 0)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function importSources(): HasMany
    {
        return $this->hasMany(ImportSource::class);
    }

    public function channelAliases(): HasMany
    {
        return $this->hasMany(ChannelAlias::class);
    }

    public function shippingMethodAliases(): HasMany
    {
        return $this->hasMany(ShippingMethodAlias::class);
    }

    public function shippingRules(): HasMany
    {
        return $this->hasMany(ShippingRule::class);
    }
}
