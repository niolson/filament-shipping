<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\TestPackageController;
use App\Http\Controllers\Auth\AzureController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\SsoCallbackController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\LabelPrintController;
use App\Http\Controllers\OAuthCallbackController;
use App\Http\Controllers\PickBatchController;
use App\Http\Controllers\QzProvisionScriptController;
use App\Http\Controllers\QzSignController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::get('/up', function () {
    // Liveness endpoint for container/web health checks.
    // Dependency-specific readiness checks belong on dedicated diagnostics routes.
    return response()->json(['status' => 'ok']);
});

Route::post('/qz/sign', [QzSignController::class, 'sign'])->name('qz.sign')->middleware(['auth', 'throttle:60,1']);

Route::get('/qz/provision-script/{platform}', [QzProvisionScriptController::class, 'download'])
    ->name('qz.provision-script')
    ->middleware('auth');

// Acknowledgement from the QZ Tray integration that a label reached a printer.
// Batch printing fires one of these per label, so the rate cap is generous.
Route::post('/labels/{package}/printed', [LabelPrintController::class, 'store'])
    ->name('labels.printed')
    ->middleware(['auth', 'throttle:300,1']);

Route::middleware(['auth', 'manager'])->group(function (): void {
    Route::get('/pick-batches/{pickBatch}/summary', [PickBatchController::class, 'summary'])
        ->name('pick-batches.summary');
    Route::get('/pick-batches/{pickBatch}/pack-slips', [PickBatchController::class, 'packSlips'])
        ->name('pick-batches.pack-slips');
});

Route::get('/oauth/{provider}/receive', [OAuthCallbackController::class, 'receive'])
    ->name('oauth.receive')
    ->middleware(['auth', 'admin', 'throttle:20,1']);

Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');

Route::get('/auth/azure/redirect', [AzureController::class, 'redirect'])->name('auth.azure.redirect');
Route::get('/auth/azure/callback', [AzureController::class, 'callback'])->name('auth.azure.callback');

// Throttled: unauthenticated, and every hit proxies an outbound call to the
// external OAuth broker — cap the rate at which it can be driven. See issue 14.
Route::get('/auth/sso/{provider}/receive', [SsoCallbackController::class, 'receive'])
    ->name('auth.sso.receive')
    ->middleware('throttle:20,1');

// Collects browser CSP violation reports for local policy tuning. Enabled only
// in local/testing; see App\Http\Middleware\ContentSecurityPolicy.
if (app()->environment(['local', 'testing'])) {
    Route::post('/csp-report', CspReportController::class)
        ->name('csp.report')
        ->withoutMiddleware([PreventRequestForgery::class]);
}

Route::prefix('api')->group(function (): void {
    if (app()->environment(['local', 'testing'])) {
        Route::get('/health', HealthController::class);
    }

    if (app()->environment(['local', 'testing'])) {
        Route::post('/test/create-package', TestPackageController::class)
            ->withoutMiddleware([PreventRequestForgery::class]);
    }
});
