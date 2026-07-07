<?php

namespace App\Filament\Resources\CarrierServiceResource\RelationManagers;

use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class SpecialServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'specialServices';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TagsInput::make('restricted_countries')
                    ->label('Restricted countries')
                    ->placeholder('e.g. US, CA')
                    ->helperText('ISO country codes this service/special-service combination is limited to. Leave empty for no country restriction.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
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
            ->headerActions([
                Actions\AttachAction::make()
                    ->recordSelectOptionsQuery(fn ($query) => $query->where('active', true))
                    ->preloadRecordSelect()
                    ->form(fn (Actions\AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Forms\Components\TagsInput::make('restricted_countries')
                            ->label('Restricted countries')
                            ->placeholder('e.g. US, CA')
                            ->helperText('ISO country codes this service/special-service combination is limited to. Leave empty for no country restriction.'),
                    ]),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DetachAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
