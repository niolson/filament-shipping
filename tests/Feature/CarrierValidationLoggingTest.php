<?php

use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;

it('routes carrier validation channels to a null handler when CARRIER_API_LOGGING is off', function (string $channel): void {
    // CARRIER_API_LOGGING is unset in the test environment, so the channels
    // must not write anywhere — payloads contain recipient PII and Amazon's
    // data protection policy forbids logging it in production.
    $logger = Log::channel($channel)->getLogger();

    expect($logger->getHandlers())->toHaveCount(1)
        ->and($logger->getHandlers()[0])->toBeInstanceOf(NullHandler::class);
})->with(['fedex-validation', 'usps-validation', 'ups-validation']);

it('refuses to run FedEx certification commands when carrier API logging is disabled', function (string $command): void {
    $this->artisan($command)
        ->expectsOutputToContain('CARRIER_API_LOGGING is disabled')
        ->assertFailed();
})->with([
    'fedex:run-etd-test --variant=a',
    'fedex:run-consolidation-test',
]);
