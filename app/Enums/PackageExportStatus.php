<?php

namespace App\Enums;

enum PackageExportStatus: string
{
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case RetryableFailed = 'retryable_failed';
    case PermanentlyFailed = 'permanently_failed';
}
