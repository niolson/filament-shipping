<?php

use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/__ip_probe', fn () => request()->ip());
});

it('resolves the real client IP from X-Forwarded-For behind trusted proxies', function (): void {
    $this->call('GET', '/__ip_probe', [], [], [], [
        'REMOTE_ADDR' => '172.18.0.5',                        // internal Docker hop (trusted)
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 162.158.1.1', // real client, Cloudflare edge
    ])->assertOk()->assertSee('203.0.113.7');
});

it('ignores a spoofed leading X-Forwarded-For entry', function (): void {
    $this->call('GET', '/__ip_probe', [], [], [], [
        'REMOTE_ADDR' => '172.18.0.5',
        'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 203.0.113.7, 162.158.1.1', // attacker, real, cf-edge
    ])->assertOk()->assertSee('203.0.113.7');
});

it('does not honor X-Forwarded-For from an untrusted (direct) connection', function (): void {
    // A connection that did NOT come through the proxy chain must not be able to
    // spoof its IP — the real peer address is used, the header is ignored.
    $this->call('GET', '/__ip_probe', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.50',
        'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
    ])->assertOk()->assertSee('203.0.113.50');
});
