<?php

namespace App\Filament\Resources\DataSources\Tables;

use App\Enums\ScheduleInterval;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DataSourcesTable
{
    private const DRIVER_LABELS = [
        DatabaseSource::class => 'Database',
        ShopifySource::class => 'Shopify',
        AmazonSource::class => 'Amazon SP-API',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('client.name')
                    ->label('Client')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('driver')
                    ->label('Driver')
                    ->formatStateUsing(fn (string $state): string => self::DRIVER_LABELS[$state] ?? class_basename($state))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('schedule_interval')
                    ->label('Schedule')
                    ->formatStateUsing(fn (?ScheduleInterval $state): string => $state?->getLabel() ?? '—')
                    ->badge()
                    ->color('info'),

                IconColumn::make('global_export')
                    ->label('Global Export')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
