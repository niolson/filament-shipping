<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
        'type',
        'encrypted',
        'group',
    ];

    protected $casts = [
        'encrypted' => 'boolean',
    ];

    /**
     * Raw value awaiting serialization + encryption at save time.
     */
    protected mixed $pendingValue = null;

    protected bool $hasPendingValue = false;

    /**
     * Serialize and encrypt the staged value on save, when `type` and `encrypted`
     * are known regardless of the order attributes were assigned. Doing this in the
     * setter is unsafe: during Model::create([...]) Eloquent fills attributes in
     * array order, so a `value` listed before `encrypted`/`type` would be
     * serialized/encrypted using the wrong (unset) flags. This is overridden on
     * save() rather than a saving event so it still runs when model events are
     * muted (e.g. seeders using WithoutModelEvents, or saveQuietly()).
     */
    public function save(array $options = []): bool
    {
        $this->persistPendingValue();

        return parent::save($options);
    }

    /**
     * Get the value attribute with automatic type casting and decryption.
     */
    public function getValueAttribute(?string $rawValue): mixed
    {
        // Reflect a value that was assigned but not yet persisted.
        if ($this->hasPendingValue) {
            return $this->pendingValue;
        }

        if ($rawValue === null) {
            return null;
        }

        // Get encrypted flag from attributes (handles pre-cast state)
        $encrypted = $this->attributes['encrypted'] ?? false;
        if (is_string($encrypted)) {
            $encrypted = filter_var($encrypted, FILTER_VALIDATE_BOOLEAN);
        }

        // Decrypt if encrypted
        $value = $encrypted ? $this->decryptValue($rawValue) : $rawValue;

        // Cast based on type
        $type = $this->attributes['type'] ?? 'string';

        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    /**
     * Stage the value; serialization/encryption is deferred to save time so it
     * uses the final `type` and `encrypted` flags regardless of assignment order.
     */
    public function setValueAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->attributes['value'] = null;
            $this->pendingValue = null;
            $this->hasPendingValue = false;

            return;
        }

        $this->pendingValue = $value;
        $this->hasPendingValue = true;
    }

    /**
     * Serialize and (optionally) encrypt the staged value into the stored column.
     */
    protected function persistPendingValue(): void
    {
        if (! $this->hasPendingValue) {
            return;
        }

        $type = $this->attributes['type'] ?? 'string';
        $encrypted = (bool) ($this->attributes['encrypted'] ?? false);

        $stringValue = match ($type) {
            'boolean' => $this->pendingValue ? '1' : '0',
            'json' => is_string($this->pendingValue) ? $this->pendingValue : json_encode($this->pendingValue),
            default => (string) $this->pendingValue,
        };

        $this->attributes['value'] = $encrypted
            ? Crypt::encryptString($stringValue)
            : $stringValue;

        $this->pendingValue = null;
        $this->hasPendingValue = false;
    }

    /**
     * Decrypt an encrypted value.
     */
    private function decryptValue(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            // Return raw value if decryption fails (e.g., not actually encrypted)
            return $value;
        }
    }
}
