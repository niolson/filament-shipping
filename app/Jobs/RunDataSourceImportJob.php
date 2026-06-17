<?php

namespace App\Jobs;

use App\Models\DataSource;
use App\Models\User;
use App\Notifications\ImportCompleted;
use App\Services\SettingsService;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RunDataSourceImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $dataSourceId,
        public int $userId,
    ) {}

    /**
     * Prevent concurrent imports of the same source (double-clicks, scheduler overlap).
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("data-source-import:{$this->dataSourceId}"))
                ->dontRelease()
                ->expireAfter($this->timeout),
        ];
    }

    public function handle(SettingsService $settings): void
    {
        $source = DataSource::find($this->dataSourceId);

        if (! $source || ! $source->active) {
            return;
        }

        if ($source->driver === AmazonSource::class && ! $settings->get('require_mfa', false)) {
            User::find($this->userId)?->notify(
                new ImportCompleted([], $source->name, ['Amazon SP-API imports require Multi-Factor Authentication to be enabled. Enable it in App Settings → Authentication.'])
            );

            return;
        }

        $result = ShipmentImportService::forRecord($source)->import();

        // ImportRunRecorder already notifies all active admins when an import
        // finishes with errors; only the success confirmation is on us here.
        if ($result->hasErrors()) {
            return;
        }

        User::find($this->userId)?->notify(
            new ImportCompleted($result->toArray(), $source->name)
        );
    }
}
