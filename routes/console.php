<?php

use App\Models\ImportSource;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

try {
    ImportSource::where('active', true)
        ->whereNotNull('schedule_interval')
        ->get()
        ->each(function (ImportSource $source): void {
            Schedule::command('shipments:import', ['--source-id' => $source->id])
                ->cron($source->schedule_interval->toCron())
                ->withoutOverlapping("import-source-{$source->id}")
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/import.log'));
        });
} catch (Throwable) {
    // DB may not be available during bootstrapping (e.g. first deploy)
}

Schedule::command('shipments:validate')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/validate.log'));
