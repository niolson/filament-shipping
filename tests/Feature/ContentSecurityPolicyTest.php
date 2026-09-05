<?php

use Illuminate\Support\Facades\Log;

it('adds the enforcing CSP header to responses', function (): void {
    $response = $this->get('/up');

    $response->assertOk();

    expect($response->headers->get('Content-Security-Policy-Report-Only'))->toBeNull();

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeNull()
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("'unsafe-inline'")
        ->and($csp)->toContain("'unsafe-eval'")
        ->and($csp)->toContain('wss://localhost:*')     // QZ Tray printing
        ->and($csp)->not->toContain('fonts.bunny.net')  // DM Sans is self-hosted
        ->and($csp)->not->toContain('ui-avatars.com');  // avatars are generated locally
});

it('logs CSP violation reports to the csp channel and returns 204', function (): void {
    Log::shouldReceive('channel')->with('csp')->once()->andReturnSelf();
    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
        return $message === 'CSP violation'
            && ($context['violated-directive'] ?? null) === 'script-src-elem';
    });

    $this->call('POST', '/csp-report', [], [], [], [], json_encode([
        'csp-report' => [
            'violated-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example/x.js',
        ],
    ]))->assertNoContent();
});
