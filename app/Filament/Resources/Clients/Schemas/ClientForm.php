<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Services\AddressReferenceService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Client Details')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true)
                            ->helperText('Short identifier used in imports and exports, e.g. "ACME".'),
                        Toggle::make('active')
                            ->default(true),
                        Toggle::make('is_default')
                            ->label('Default client')
                            ->helperText('Shipments with no client assigned use this client.')
                            ->default(false),
                    ])
                    ->columns(2),

                Section::make('Return Address')
                    ->description('Ship-from address shown on labels for this client\'s shipments. Leave blank to use the warehouse address.')
                    ->schema([
                        TextInput::make('return_company')
                            ->label('Company')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('return_name')
                            ->label('Contact Name')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('return_address1')
                            ->label('Address')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('return_address2')
                            ->label('Apartment, suite, etc.')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Grid::make(['default' => 1, 'md' => 3])
                            ->schema([
                                TextInput::make('return_city')
                                    ->label('City')
                                    ->maxLength(255),
                                TextInput::make('return_state_or_province')
                                    ->label('State / Province')
                                    ->maxLength(100),
                                TextInput::make('return_postal_code')
                                    ->label('Postal Code')
                                    ->maxLength(20),
                            ])
                            ->columnSpanFull(),
                        Select::make('return_country')
                            ->label('Country')
                            ->options(fn (): array => app(AddressReferenceService::class)->getCountryOptions())
                            ->searchable()
                            ->native(false)
                            ->columnSpanFull(),
                        TextInput::make('return_phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(50)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(fn (?object $record): bool => ! $record?->hasReturnAddress()),
            ]);
    }
}
