<?php

namespace App\Filament\Concerns;

use App\Models\Client;
use App\Services\SettingsService;
use Filament\Forms\Components\Select;
use Filament\Tables;

trait HasMultiClientFilter
{
    protected function buildClientFilter(string $column = 'shipments.client_id'): ?Tables\Filters\Filter
    {
        if (! app(SettingsService::class)->get('multi_client_enabled', false)) {
            return null;
        }

        return Tables\Filters\Filter::make('client')
            ->form([
                Select::make('client_id')
                    ->label('Client')
                    ->options(fn () => Client::orderBy('name')->pluck('name', 'id'))
                    ->native(false)
                    ->placeholder('All clients'),
            ])
            ->query(fn ($query, array $data) => $query->when(
                $data['client_id'],
                fn ($q, $id) => $q->where($column, $id)
            ));
    }
}
