<?php

namespace App\Filament\Resources\LabelBatchResource\RelationManagers;

use App\Filament\Resources\ShipmentResource;
use App\Filament\Support\CarrierLogoColumn;
use App\Models\Location;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class LabelBatchItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Batch Items';

    public function table(Table $table): Table
    {
        return $table
            ->poll(fn () => $this->getOwnerRecord()->isComplete() ? null : '5s')
            ->modifyQueryUsing(fn ($query) => $query->with(['shipment', 'package']))
            ->columns([
                Tables\Columns\TextColumn::make('shipment.shipment_reference')
                    ->label('Reference')
                    ->url(fn ($record) => $record->shipment_id
                        ? ShipmentResource::getUrl('view', ['record' => $record->shipment_id])
                        : null),
                Tables\Columns\TextColumn::make('status')
                    ->badge(),
                Tables\Columns\TextColumn::make('tracking_number')
                    ->label('Tracking')
                    ->placeholder('—')
                    ->copyable(),
                CarrierLogoColumn::make('carrier')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('service')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('cost')
                    ->money('USD')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('package.label_printed_at')
                    ->label('Printed')
                    ->boolean()
                    ->tooltip(fn ($record): string => $record->package?->label_printed_at
                        ? 'Last printed '.$record->package->label_printed_at->tz(Location::timezone())->format('M j, Y g:i A')
                        : 'Not printed'),
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Error')
                    ->placeholder('—')
                    ->wrap()
                    ->color('danger'),
            ])
            ->defaultSort('id', 'asc');
    }
}
