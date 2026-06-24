<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class CarrierAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'carrier_id',
        'name',
        'credentials',
        'secret_credentials',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'credentials' => 'array',
            'secret_credentials' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (CarrierAccount $account): void {
            if ($account->wasChanged(['credentials', 'secret_credentials'])) {
                $account->clearTokenCaches();
            }
        });

        static::deleted(function (CarrierAccount $account): void {
            $account->clearTokenCaches();
        });
    }

    /**
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * @return HasMany<CarrierAccountScope, $this>
     */
    public function scopes(): HasMany
    {
        return $this->hasMany(CarrierAccountScope::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function credential(string $key): mixed
    {
        return $this->credentials[$key] ?? null;
    }

    public function secret(string $key): mixed
    {
        return $this->secret_credentials[$key] ?? null;
    }

    public function mergeCredential(string $key, mixed $value): void
    {
        $this->credentials = array_merge($this->credentials ?? [], [$key => $value]);
    }

    public function mergeSecret(string $key, mixed $value): void
    {
        $this->secret_credentials = array_merge($this->secret_credentials ?? [], [$key => $value]);
    }

    public function connectionStatus(): string
    {
        return match ($this->carrier?->name) {
            'USPS', 'UPS' => filled($this->secret('oauth_token')) ? 'Connected' : 'Needs Setup',
            'FedEx' => filled($this->secret('child_key')) ? 'Connected' : 'Needs Setup',
            default => 'Active',
        };
    }

    /**
     * Returns eligible CarrierAccount(s) for a shipment in priority order.
     *
     * Resolution priority (most specific first):
     *   1. (location, client)  — explicit client override at this location
     *   2. (location, null)    — location default
     *   3. (null, client)      — client default across all locations
     *   4. (null, null)        — global default for this carrier
     *
     * When the winning scope has rate_shop=true, the location-default account is
     * also returned so the caller can fetch rates from both and pick the cheapest.
     */
    public static function resolveForShipment(
        int $carrierId,
        ?int $locationId,
        ?int $clientId,
    ): Collection {
        $scopes = CarrierAccountScope::with('carrierAccount')
            ->whereHas('carrierAccount', fn (Builder $q) => $q->where('active', true))
            ->where('carrier_id', $carrierId)
            ->where(function (Builder $q) use ($locationId, $clientId): void {
                $q->where(fn ($q) => $q->where('location_id', $locationId)->where('client_id', $clientId))
                    ->orWhere(fn ($q) => $q->where('location_id', $locationId)->whereNull('client_id'))
                    ->orWhere(fn ($q) => $q->whereNull('location_id')->where('client_id', $clientId))
                    ->orWhere(fn ($q) => $q->whereNull('location_id')->whereNull('client_id'));
            })
            ->get();

        if ($scopes->isEmpty()) {
            return new Collection;
        }

        $priority = fn (CarrierAccountScope $scope): int => match (true) {
            $scope->location_id === $locationId && $scope->client_id === $clientId => 0,
            $scope->location_id === $locationId && $scope->client_id === null => 1,
            $scope->location_id === null && $scope->client_id === $clientId => 2,
            default => 3,
        };

        $sorted = $scopes->sortBy($priority);
        $bestScope = $sorted->first();
        $result = new Collection([$bestScope->carrierAccount]);

        if ($bestScope->rate_shop && $locationId !== null) {
            $locationDefault = $sorted->first(
                fn (CarrierAccountScope $s) => $priority($s) === 1
                    && $s->carrierAccount->isNot($bestScope->carrierAccount)
            );

            if ($locationDefault) {
                $result->push($locationDefault->carrierAccount);
            }
        }

        return $result;
    }

    private function clearTokenCaches(): void
    {
        // USPS per-account caches
        Cache::forget("usps_payment_authorization_token:{$this->id}");
        Cache::forget("usps_oauth_token:{$this->id}");
        Cache::forget("usps_authenticator:{$this->id}");

        // FedEx: global caches + per-account child-key cache (keyed by child_key hash)
        Cache::forget('fedex_authenticator');
        Cache::forget('fedex_authenticator_sandbox');
        if ($childKey = $this->secret('child_key')) {
            $env = $this->credential('child_env') ?? 'production';
            Cache::forget('fedex_authenticator_child_'.$env.'_'.hash('sha256', $childKey));
        }

        // UPS: global caches + per-account caches (used when account has its own client_id)
        Cache::forget('ups_authenticator');
        Cache::forget("ups_authenticator:{$this->id}");
        Cache::forget('ups_oauth_token');
        Cache::forget("ups_oauth_token:{$this->id}");
    }
}
