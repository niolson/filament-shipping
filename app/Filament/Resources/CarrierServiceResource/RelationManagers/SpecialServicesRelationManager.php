<?php

namespace App\Filament\Resources\CarrierServiceResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only view of which special services this carrier service is scoped to
 * carry. Scope rows are code-owned carrier facts (seeded by
 * CarrierServiceSpecialServiceSeeder from the carrier restriction research) —
 * editing them in the UI would drift from reality and fight reseeding.
 */
class SpecialServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'specialServices';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->description('Managed in code (CarrierServiceSpecialServiceSeeder) — these rows record researched carrier restrictions and are read-only here.')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'compliance' => 'danger',
                        'delivery' => 'info',
                        'pickup' => 'warning',
                        'notifications' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('pivot.restricted_countries')
                    ->label('Restricted countries')
                    ->badge()
                    ->placeholder('No restriction'),
            ])
            ->filters([])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
