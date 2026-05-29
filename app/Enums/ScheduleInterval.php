<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ScheduleInterval: string implements HasLabel
{
    case EveryFiveMinutes = 'every_5_minutes';
    case EveryFifteenMinutes = 'every_15_minutes';
    case EveryThirtyMinutes = 'every_30_minutes';
    case Hourly = 'hourly';
    case EverySixHours = 'every_6_hours';
    case Daily = 'daily';

    public function toCron(): string
    {
        return match ($this) {
            self::EveryFiveMinutes => '*/5 * * * *',
            self::EveryFifteenMinutes => '*/15 * * * *',
            self::EveryThirtyMinutes => '*/30 * * * *',
            self::Hourly => '0 * * * *',
            self::EverySixHours => '0 */6 * * *',
            self::Daily => '0 0 * * *',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::EveryFiveMinutes => 'Every 5 minutes',
            self::EveryFifteenMinutes => 'Every 15 minutes',
            self::EveryThirtyMinutes => 'Every 30 minutes',
            self::Hourly => 'Hourly',
            self::EverySixHours => 'Every 6 hours',
            self::Daily => 'Daily',
        };
    }
}
