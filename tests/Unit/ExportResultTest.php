<?php

use App\Services\ShipmentImport\ExportResult;

it('reports whether any errors are permanent', function (array $errors, array $retryableErrors, bool $expected): void {
    $result = new ExportResult(
        success: false,
        errors: $errors,
        retryableErrors: $retryableErrors,
    );

    expect($result->hasPermanentErrors())->toBe($expected);
})->with([
    'no errors' => [[], [], false],
    'retryable errors only' => [['Throttled'], ['Throttled'], false],
    'permanent errors only' => [['Invalid input'], [], true],
    'mixed errors' => [['Throttled', 'Invalid input'], ['Throttled'], true],
]);
