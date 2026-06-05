<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'logo',
        'company_name',
        'custom_message',
        'return_instructions',
        'is_default',
        'active',
        'return_company',
        'return_name',
        'return_address1',
        'return_address2',
        'return_city',
        'return_state_or_province',
        'return_postal_code',
        'return_country',
        'return_phone',
        'export_import_source_id',
        'pick_fee_first_item',
        'pick_fee_additional_item',
        'label_fee_per_package',
    ];

    public function hasReturnAddress(): bool
    {
        return filled($this->return_address1) && filled($this->return_city) && filled($this->return_postal_code);
    }

    protected $casts = [
        'is_default' => 'boolean',
        'active' => 'boolean',
        'pick_fee_first_item' => 'decimal:2',
        'pick_fee_additional_item' => 'decimal:2',
        'label_fee_per_package' => 'decimal:2',
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

    public function exportSource(): BelongsTo
    {
        return $this->belongsTo(ImportSource::class, 'export_import_source_id');
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
