<?php

namespace App\Models;

use App\Enums\ScheduleInterval;
use App\Models\Concerns\HasDefaultClient;
use Database\Factories\DataSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSource extends Model
{
    /** @use HasFactory<DataSourceFactory> */
    use HasDefaultClient, HasFactory;

    /** @var list<string> Keys that belong in the encrypted secret_settings column. */
    public const SECRET_SETTINGS_KEYS = ['access_token', 'oauth_access_token', 'client_id', 'client_secret', 'refresh_token', 'db_password'];

    protected $table = 'data_sources';

    protected $fillable = [
        'client_id',
        'name',
        'driver',
        'active',
        'global_export',
        'schedule_interval',
        'settings',
        'secret_settings',
    ];

    protected $hidden = [
        'secret_settings',
    ];

    protected $casts = [
        'active' => 'boolean',
        'global_export' => 'boolean',
        'schedule_interval' => ScheduleInterval::class,
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

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
