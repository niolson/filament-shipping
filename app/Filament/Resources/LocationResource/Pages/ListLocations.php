<?php

namespace App\Filament\Resources\LocationResource\Pages;

use App\Filament\Pages\Settings;
use App\Filament\Resources\LocationResource;
use App\Services\SettingsService;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLocations extends ListRecords
{
    protected static string $resource = LocationResource::class;

    public function mount(): void
    {
        if (! app(SettingsService::class)->get('multi_location_enabled', false)) {
            $this->redirect(Settings::getUrl());

            return;
        }

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
