<?php

use App\Logging\Processors\PiiRedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;

$makeCarrierLogRecord = fn (array $context): LogRecord => new LogRecord(
    datetime: new DateTimeImmutable,
    channel: 'usps-validation',
    level: Level::Debug,
    message: 'RATE RESPONSE',
    context: $context,
);

it('redacts PII keys from log record context by default', function () use ($makeCarrierLogRecord): void {
    config(['logging.carrier_api_logging' => false]);

    $record = $makeCarrierLogRecord([
        'body' => [
            'destination' => [
                'streetAddress' => '123 Main St',
                'city' => 'Memphis',
                'ZIPCode' => '38103',
            ],
            'mailClass' => 'PRIORITY_MAIL',
        ],
    ]);

    $result = (new PiiRedactionProcessor)($record);

    expect($result->context)->toBe([
        'body' => [
            'destination' => [
                'streetAddress' => '[REDACTED]',
                'city' => '[REDACTED]',
                'ZIPCode' => '[REDACTED]',
            ],
            'mailClass' => 'PRIORITY_MAIL',
        ],
    ]);
});

it('redacts UPS Name fields and carrier label image payloads', function () use ($makeCarrierLogRecord): void {
    config(['logging.carrier_api_logging' => false]);

    $record = $makeCarrierLogRecord([
        'Shipper' => ['Name' => 'Jane Doe', 'ShipperNumber' => 'ABC123'],
        'ShipTo' => ['Name' => 'John Smith'],
        'pieceResponses' => [
            ['packageDocuments' => [['encodedLabel' => 'base64pdfbytes']]],
        ],
        'PackageResults' => [
            ['ShippingLabel' => ['GraphicImage' => 'base64gifbytes']],
        ],
        'serviceName' => 'FedEx Ground',
    ]);

    $result = (new PiiRedactionProcessor)($record);

    expect($result->context)->toBe([
        'Shipper' => ['Name' => '[REDACTED]', 'ShipperNumber' => 'ABC123'],
        'ShipTo' => ['Name' => '[REDACTED]'],
        'pieceResponses' => [
            ['packageDocuments' => [['encodedLabel' => '[REDACTED]']]],
        ],
        'PackageResults' => [
            ['ShippingLabel' => ['GraphicImage' => '[REDACTED]']],
        ],
        'serviceName' => 'FedEx Ground',
    ]);
});

it('passes context through unredacted when CARRIER_API_LOGGING is enabled', function () use ($makeCarrierLogRecord): void {
    config(['logging.carrier_api_logging' => true]);

    $context = [
        'body' => [
            'address' => [
                'streetAddress' => '123 Main St',
            ],
        ],
    ];

    $record = $makeCarrierLogRecord($context);

    $result = (new PiiRedactionProcessor)($record);

    expect($result->context)->toBe($context);
});
