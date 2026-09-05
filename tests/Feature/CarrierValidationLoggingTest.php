<?php

use App\Logging\Processors\PiiRedactionProcessor;
use Illuminate\Support\Facades\Log;

it('redacts carrier validation channel logs by default via PiiRedactionProcessor', function (string $channel): void {
    // CARRIER_API_LOGGING is unset in the test environment, so payloads must
    // be redacted before they're written — recipient PII must never land in
    // these files by default.
    $logger = Log::channel($channel)->getLogger();

    $hasRedactionProcessor = collect($logger->getProcessors())
        ->contains(fn ($processor): bool => $processor instanceof PiiRedactionProcessor);

    expect($hasRedactionProcessor)->toBeTrue();
})->with(['fedex-validation', 'usps-validation', 'ups-validation']);

it('refuses to run FedEx certification commands when carrier API logging is disabled', function (string $command): void {
    $this->artisan($command)
        ->expectsOutputToContain('CARRIER_API_LOGGING is disabled')
        ->assertFailed();
})->with([
    'fedex:run-etd-test --variant=a',
    'fedex:run-consolidation-test',
]);
