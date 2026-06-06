<?php

namespace App\Filament\Resources\DataSources\Pages;

use App\Filament\Resources\DataSources\DataSourceResource;
use App\Models\DataSource;
use Filament\Resources\Pages\CreateRecord;

class CreateDataSource extends CreateRecord
{
    protected static string $resource = DataSourceResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $submitted = $data['settings'] ?? [];
        $secrets = [];

        foreach (DataSource::SECRET_SETTINGS_KEYS as $key) {
            if (array_key_exists($key, $submitted) && filled($submitted[$key])) {
                $secrets[$key] = $submitted[$key];
            }
            unset($submitted[$key]);
        }

        $data['settings'] = $submitted;
        $data['secret_settings'] = $secrets ?: null;

        return $data;
    }
}
