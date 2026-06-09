<?php

use App\Logging\SentryPiiScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;

it('redacts PII keys from event extras, contexts, request, and breadcrumbs', function (): void {
    $event = Event::createEvent();

    $event->setExtra([
        'first_name' => 'Jane',
        'shipment_id' => 42,
        'nested' => ['address1' => '123 Main St', 'carrier' => 'usps'],
    ]);

    $event->setContext('shipment', [
        'recipient_email' => 'jane@example.com',
        'tracking_number' => '9400111899223344556677',
    ]);

    $event->setRequest([
        'url' => 'https://app.example.com/manual-ship',
        'data' => ['phone' => '555-0100'],
    ]);

    $event->setBreadcrumb([
        new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'log',
            'Validating address',
            ['city' => 'Memphis', 'status' => 'pending'],
        ),
    ]);

    $scrubbed = SentryPiiScrubber::scrub($event);

    expect($scrubbed->getExtra())->toBe([
        'first_name' => '[REDACTED]',
        'shipment_id' => 42,
        'nested' => ['address1' => '[REDACTED]', 'carrier' => 'usps'],
    ]);

    expect($scrubbed->getContexts()['shipment'])->toBe([
        'recipient_email' => '[REDACTED]',
        'tracking_number' => '9400111899223344556677',
    ]);

    expect($scrubbed->getRequest())->toBe([
        'url' => 'https://app.example.com/manual-ship',
        'data' => ['phone' => '[REDACTED]'],
    ]);

    expect($scrubbed->getBreadcrumbs()[0]->getMetadata())->toBe([
        'city' => '[REDACTED]',
        'status' => 'pending',
    ]);
});

it('keeps sentry configured with PII-safe options', function (): void {
    // Amazon's data protection policy forbids sending buyer PII to third
    // parties — these options are the enforcement points.
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.max_request_body_size'))->toBe('none')
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse()
        ->and(config('sentry.tracing.sql_bindings'))->toBeFalse()
        ->and(config('sentry.before_send'))->toBe([SentryPiiScrubber::class, 'scrub']);
});
