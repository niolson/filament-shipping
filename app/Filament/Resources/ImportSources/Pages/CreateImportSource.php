<?php

namespace App\Filament\Resources\ImportSources\Pages;

use App\Filament\Resources\ImportSources\ImportSourceResource;
use App\Models\ImportSource;
use Filament\Resources\Pages\CreateRecord;

class CreateImportSource extends CreateRecord
{
    protected static string $resource = ImportSourceResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $submitted = $data['settings'] ?? [];
        $secrets = [];

        foreach (ImportSource::SECRET_SETTINGS_KEYS as $key) {
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
