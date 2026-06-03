<?php

namespace App\Models;

use App\Models\Concerns\HasDefaultClient;
use Database\Factories\ChannelAliasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelAlias extends Model
{
    /** @use HasFactory<ChannelAliasFactory> */
    use HasDefaultClient, HasFactory;

    protected $fillable = [
        'client_id',
        'reference',
        'channel_id',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
