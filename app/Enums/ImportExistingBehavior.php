<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ImportExistingBehavior: string implements HasLabel
{
    case Update = 'update';
    case Skip = 'skip';
    case UpdateIfChanged = 'update_if_changed';

    public static function default(): self
    {
        return self::UpdateIfChanged;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Update => 'Always update from source',
            self::Skip => 'Skip (keep local changes)',
            self::UpdateIfChanged => 'Update only if source data changed',
        };
    }
}
