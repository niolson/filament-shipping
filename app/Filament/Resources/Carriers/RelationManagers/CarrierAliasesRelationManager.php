<?php

namespace App\Filament\Resources\Carriers\RelationManagers;

use App\Models\CarrierAlias;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CarrierAliasesRelationManager extends RelationManager
{
    protected static string $relationship = 'carrierAliases';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('alias')
                    ->required()
                    ->maxLength(255)
                    ->rule(fn (?CarrierAlias $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $message = CarrierAlias::conflictMessage(
                            CarrierAlias::lookupKey((string) $value),
                            (int) $this->getOwnerRecord()->getKey(),
                            $record?->exists ? (int) $record->getKey() : null,
                        );

                        if ($message !== null) {
                            $fail($message);
                        }
                    })
                    ->helperText('Carrier names from postage sources that should normalize to this carrier.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('alias')
            ->columns([
                TextColumn::make('alias')
                    ->searchable(),
            ])
            ->filters([
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
