<?php

namespace App\Observers;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditableObserver
{
    public function created(Model $model): void
    {
        AuditLog::record(
            AuditAction::ModelCreated,
            $model,
            newValues: $this->filterAttributes($model->getAttributes(), $model),
        );
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();

        // Skip if only timestamps changed
        $meaningful = array_diff_key($changes, array_flip(['created_at', 'updated_at']));
        if (empty($meaningful)) {
            return;
        }

        $old = [];
        $new = [];
        foreach ($meaningful as $key => $value) {
            $old[$key] = $model->getOriginal($key);
            $new[$key] = $value;
        }

        $old = $this->filterAttributes($old, $model);
        $new = $this->filterAttributes($new, $model);

        AuditLog::record(AuditAction::ModelUpdated, $model, oldValues: $old, newValues: $new);
    }

    public function deleted(Model $model): void
    {
        AuditLog::record(
            AuditAction::ModelDeleted,
            $model,
            oldValues: $this->filterAttributes($model->getAttributes(), $model),
        );
    }

    /**
     * Remove timestamps and mask sensitive attributes from audit data: hidden
     * attributes (password, remember_token) become `[hidden]`, and any
     * encrypted-cast column (e.g. secret_credentials, secret_settings) becomes
     * `[encrypted]` so no secret — even in ciphertext — lands in the audit trail.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filterAttributes(array $attributes, Model $model): array
    {
        // Remove timestamps
        unset($attributes['created_at'], $attributes['updated_at']);

        // Mask hidden attributes
        foreach ($model->getHidden() as $hidden) {
            if (array_key_exists($hidden, $attributes)) {
                $attributes[$hidden] = '[hidden]';
            }
        }

        // Mask encrypted columns (secret_credentials, secret_settings, etc.)
        foreach ($model->getCasts() as $key => $cast) {
            if (array_key_exists($key, $attributes) && str_starts_with($cast, 'encrypted')) {
                $attributes[$key] = '[encrypted]';
            }
        }

        return $attributes;
    }

    /**
     * Register this observer on multiple models.
     *
     * @param  array<class-string<Model>>  $models
     */
    public static function observe(array $models): void
    {
        foreach ($models as $model) {
            $model::observe(static::class);
        }
    }
}
