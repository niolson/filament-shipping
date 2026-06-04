<?php

namespace App\Filament\Resources\ShippingMethodResource\RelationManagers;

use App\Services\ClientContext;
use App\Services\SettingsService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class AliasesRelationManager extends RelationManager
{
    protected static string $relationship = 'aliases';

    public function form(Schema $form): Schema
    {
        $multiClient = app(SettingsService::class)->get('multi_client_enabled', false);

        return $form
            ->schema([
                Forms\Components\Select::make('client_id')
                    ->label('Client')
                    ->relationship('client', 'name')
                    ->required($multiClient)
                    ->visible($multiClient)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('reference')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        table: 'shipping_method_aliases',
                        column: 'reference',
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($rule, Get $get) => $rule->where(
                            'client_id',
                            $get('client_id') ?? app(ClientContext::class)->id()
                        ),
                    ),
            ]);
    }

    public function table(Table $table): Table
    {
        $multiClient = app(SettingsService::class)->get('multi_client_enabled', false);

        return $table
            ->recordTitleAttribute('reference')
            ->columns([
                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->visible($multiClient),
                Tables\Columns\TextColumn::make('reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Actions\CreateAction::make(),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->groupedBulkActions([
                Actions\DeleteBulkAction::make(),
            ]);
    }
}
