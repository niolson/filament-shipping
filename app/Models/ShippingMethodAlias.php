<?php

namespace App\Models;

use App\Models\Concerns\HasDefaultClient;
use Database\Factories\ShippingMethodAliasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingMethodAlias extends Model
{
    /** @use HasFactory<ShippingMethodAliasFactory> */
    use HasDefaultClient, HasFactory;

    protected $fillable = [
        'client_id',
        'reference',
        'shipping_method_id',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }
}
