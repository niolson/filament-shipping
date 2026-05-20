<?php

namespace App\Filament\Resources\PickBatches\Schemas;

use App\Models\Location;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class PickBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(4)
                    ->schema([
                        TextEntry::make('id')
                            ->label('Batch #'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('total_shipments')
                            ->label('Shipments'),
                        TextEntry::make('user.name')
                            ->label('Created By'),
                        TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime('M j, Y g:i A', timezone: Location::timezone()),
                        TextEntry::make('completed_at')
                            ->label('Completed')
                            ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                            ->placeholder('—'),
                        IconEntry::make('summary_printed_at')
                            ->label('Summary Printed')
                            ->boolean()
                            ->trueIcon('heroicon-o-check-circle')
                            ->trueColor('success')
                            ->falseIcon('heroicon-o-minus-circle')
                            ->falseColor('gray')
                            ->tooltip(fn ($record) => $record->summary_printed_at
                                ? 'Printed '.$record->summary_printed_at->tz(Location::timezone())->format('M j, Y g:i A')
                                : 'Not printed'),
                    ]),
            ]);
    }
}
