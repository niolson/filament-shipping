<?php

namespace App\Models\Concerns;

use App\Models\Client;

trait HasDefaultClient
{
    protected static function bootHasDefaultClient(): void
    {
        static::creating(function (self $model): void {
            if ($model->client_id === null) {
                $model->client_id = Client::where('is_default', true)->value('id');
            }
        });
    }
}
