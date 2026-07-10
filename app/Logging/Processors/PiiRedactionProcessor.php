<?php

namespace App\Logging\Processors;

use App\Logging\PiiRedactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Redacts PII from carrier API log records by default. Set
 * CARRIER_API_LOGGING=true to see unredacted payloads (e.g. for FedEx
 * certification runs, which require real evidence).
 */
class PiiRedactionProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        if (config('logging.carrier_api_logging')) {
            return $record;
        }

        return $record->with(context: PiiRedactor::redact($record->context));
    }
}
