<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportSource extends Model
{
    use HasFactory;

    /** @var list<string> Keys that belong in the encrypted secret_settings column. */
    public const SECRET_SETTINGS_KEYS = ['access_token', 'oauth_access_token', 'client_id', 'client_secret', 'refresh_token', 'db_password'];

    protected $fillable = [
        'client_id',
        'config_key',
        'name',
        'driver',
        'active',
        'settings',
        'secret_settings',
    ];

    protected $casts = [
        'active' => 'boolean',
        'settings' => 'array',
        'secret_settings' => 'encrypted:array',
    ];

    public function secret(string $key): mixed
    {
        return ($this->secret_settings ?? [])[$key] ?? null;
    }

    public function mergeSecret(string $key, mixed $value): void
    {
        $this->secret_settings = array_merge($this->secret_settings ?? [], [$key => $value]);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
