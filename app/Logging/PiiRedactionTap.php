<?php

namespace App\Logging;

use App\Logging\Processors\PiiRedactionProcessor;
use Illuminate\Log\Logger;

class PiiRedactionTap
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(app(PiiRedactionProcessor::class));
    }
}
