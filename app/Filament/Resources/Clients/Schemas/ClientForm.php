<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Models\ImportSource;
use App\Services\AddressReferenceService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
                        FileUpload::make('logo')
                            ->label('Pack Slip Logo')
                            ->helperText('Logo printed on pack slips for this client. Recommended: landscape image, PNG or JPG.')
                            ->disk('public')
                            ->directory('logos')
                            ->visibility('public')
                            ->image()
                            ->panelLayout('grid')
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Pack Slip')
                    ->description('Branding and messaging printed on pack slips for this client.')
                    ->schema([
                        TextInput::make('company_name')
                            ->label('Company Name')
                            ->maxLength(255)
                            ->helperText('Name shown in the return address on pack slips. Defaults to client name if blank.')
                            ->columnSpanFull(),
                        Textarea::make('custom_message')
                            ->label('Custom Message')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Optional message printed at the bottom of each pack slip.')
                            ->columnSpanFull(),
                        Textarea::make('return_instructions')
                            ->label('Return Instructions')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Optional return instructions printed at the bottom of each pack slip.')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(fn (?object $record): bool => blank($record?->company_name) && blank($record?->custom_message) && blank($record?->return_instructions))
                    ->columns(2),

                Section::make('Export')
                    ->description('Where shipping data is sent after a package ships. Leave blank to export back to the originating import source (symmetrical default).')
                    ->schema([
                        Select::make('export_import_source_id')
                            ->label('Export Destination')
                            ->options(fn () => ImportSource::where('active', true)->orderBy('name')->pluck('name', 'id'))
                            ->nullable()
                            ->searchable()
                            ->native(false)
                            ->helperText('Override the default. Only needed when the export destination differs from the import source.')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(fn (?object $record): bool => blank($record?->export_import_source_id))
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
