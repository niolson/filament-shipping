<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects unauthenticated requests to login', function (): void {
    $this->get(route('qz.provision-script', 'windows'))
        ->assertRedirect('/login');
});

it('serves the Windows script with the site URL baked in', function (): void {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('qz.provision-script', 'windows'));

    $response->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="install-qz-cert.ps1"');

    $body = $response->getContent();
    expect($body)->toContain('$Url = "'.url('/').'"')
        ->and($body)->not->toContain('__POLYBAG_URL__')
        // Windows PowerShell needs CRLF line endings.
        ->and($body)->toContain("\r\n")
        ->and($body)->not->toContain('Add-Type')
        // Guard validates URL shape, not the placeholder literal — otherwise baking
        // rewrites the guard too and it always trips. Regression for that bug.
        ->and($body)->toContain("-notmatch '^https?://'")
        ->and($body)->not->toContain('$Url -eq "'.url('/').'"')
        // qz-tray.properties must be written WITHOUT a BOM (Set-Content -Encoding
        // UTF8 adds one, which QZ rejects). Regression for that bug.
        ->and($body)->toContain('System.Text.UTF8Encoding($false)')
        ->and($body)->not->toContain('-Encoding UTF8')
        // Trust anchor via authcert.override (allowed.dat alone does not suppress
        // the prompt on current QZ versions), pointed at the install-dir cert.
        ->and($body)->toContain('authcert.override=')
        ->and($body)->toContain('($certPath -replace');
});

it('serves the unix script with the site URL baked in', function (): void {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('qz.provision-script', 'unix'));

    $response->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="install-qz-cert.sh"');

    $body = $response->getContent();
    expect($body)->toContain(url('/'))
        ->and($body)->not->toContain('__POLYBAG_URL__')
        // Guard validates URL shape, not the placeholder literal (see windows test).
        ->and($body)->toContain('=~ ^https?://')
        ->and($body)->not->toContain('"$URL" == "'.url('/').'"');
});

it('serves a self-elevating Windows launcher with the embedded script and URL', function (): void {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('qz.provision-script', 'windows-cmd'));

    $response->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="install-qz-cert.cmd"');

    $body = $response->getContent();

    // Batch header self-elevates and the skip count matches the header line count.
    expect($body)->toContain('Start-Process -Verb RunAs')
        ->and($body)->toContain('Select-Object -Skip 6')
        // Embedded PowerShell payload, with the URL baked in.
        ->and($body)->toContain('authcert.override=')
        ->and($body)->toContain(url('/'))
        ->and($body)->not->toContain('__POLYBAG_URL__')
        ->and($body)->not->toContain('__SKIP__');
});

it('ignores a spoofed Host header and bakes in the configured app URL', function (): void {
    config(['app.url' => 'https://real.polybag.app']);
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('qz.provision-script', 'unix'), [
        'Host' => 'evil-attacker-host.example',
    ]);

    $body = $response->assertOk()->getContent();
    expect($body)->toContain('https://real.polybag.app')
        ->and($body)->not->toContain('evil-attacker-host.example');
});

it('returns 404 for an unknown platform', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('qz.provision-script', 'solaris'))
        ->assertNotFound();
});
