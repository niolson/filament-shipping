<?php

namespace App\Filament\Resources\CarrierAccounts\Pages;

use App\Filament\Resources\CarrierAccounts\CarrierAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCarrierAccounts extends ListRecords
{
    protected static string $resource = CarrierAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
