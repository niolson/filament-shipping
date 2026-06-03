<?php

namespace App\Models\Concerns;

use App\Services\ClientContext;

trait HasDefaultClient
{
    protected static function bootHasDefaultClient(): void
    {
        static::creating(function (self $model): void {
            if ($model->client_id === null) {
                $model->client_id = app(ClientContext::class)->id();
            }
        });
    }
}
