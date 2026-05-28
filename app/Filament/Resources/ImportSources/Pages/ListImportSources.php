<?php

namespace App\Filament\Resources\ImportSources\Pages;

use App\Filament\Resources\ImportSources\ImportSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImportSources extends ListRecords
{
    protected static string $resource = ImportSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
