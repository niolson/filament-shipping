<?php

use App\Enums\Role;
use App\Models\User;
use App\Services\OAuthService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => str_repeat('ab', 32),
        'services.oauth.instance_id' => 'test-instance',
        'app.url' => 'https://test.polybag.app',
    ]);

    $this->dataSource = createShopifyDataSource();
});

it('stores tokens on a data source on valid receive with transfer code', function (): void {
    $user = User::factory()->admin()->create();
    $nonce = 'valid-nonce-token';

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'shopify',
            'access_token' => 'shpat_live_token',
            'nonce' => $nonce,
            'extra' => ['scope' => 'read_orders,write_fulfillments'],
        ]),
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'oauth_state.shopify' => $nonce,
            'oauth_data_source_id.shopify' => $this->dataSource->id,
        ])
        ->get('/oauth/shopify/receive?transfer_code=abc123');

    $response->assertRedirect(route('filament.app.resources.data-sources.edit', $this->dataSource->id));
    $response->assertSessionHas('oauth_notification.status', 'success');

    $this->dataSource->refresh();
    expect($this->dataSource->secret('oauth_access_token'))->toBe('shpat_live_token')
        ->and($this->dataSource->settings['auth_mode'])->toBe('authorization_code')
        ->and($this->dataSource->settings['oauth_connected_at'])->not->toBeNull();
});

it('forbids non-admin users from receiving oauth callbacks', function (): void {
    $user = User::factory()->create(['role' => Role::User]);

    Http::fake();

    $this->actingAs($user)
        ->withSession(['oauth_state.shopify' => 'nonce'])
        ->get('/oauth/shopify/receive?transfer_code=abc123')
        ->assertForbidden();

    Http::assertNothingSent();
});

it('rejects receive with mismatched nonce', function (): void {
    $user = User::factory()->admin()->create();

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'shopify',
            'access_token' => 'shpat_token',
            'nonce' => 'broker-nonce',
            'extra' => [],
        ]),
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'oauth_state.shopify' => 'different-nonce',
            'oauth_data_source_id.shopify' => $this->dataSource->id,
        ])
        ->get('/oauth/shopify/receive?transfer_code=abc123');

    $response->assertRedirect(route('filament.app.resources.data-sources.edit', $this->dataSource->id));
    $response->assertSessionHas('oauth_notification.status', 'danger');

    expect($this->dataSource->fresh()->secret('oauth_access_token'))->toBeNull();
});

it('handles error redirect from broker', function (): void {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)
        ->get('/oauth/shopify/receive?error=access_denied&error_description=User+denied+access');

    $response->assertRedirect(route('filament.app.pages.settings'));
    $response->assertSessionHas('oauth_notification.status', 'danger');
    $response->assertSessionHas('oauth_notification.title', 'Connection failed: User denied access');
});

it('handles missing transfer code', function (): void {
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)
        ->get('/oauth/shopify/receive');

    $response->assertRedirect(route('filament.app.pages.settings'));
    $response->assertSessionHas('oauth_notification.status', 'danger');
});

it('handles broker claim failure', function (): void {
    $user = User::factory()->admin()->create();

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response('Transfer code not found', 404),
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            'oauth_state.shopify' => 'nonce',
            'oauth_data_source_id.shopify' => $this->dataSource->id,
        ])
        ->get('/oauth/shopify/receive?transfer_code=expired-code');

    $response->assertRedirect(route('filament.app.resources.data-sources.edit', $this->dataSource->id));
    $response->assertSessionHas('oauth_notification.status', 'danger');
});

it('initiates a data source authorization via the broker', function (): void {
    $url = app(OAuthService::class)->initiateAuthorizationForDataSource('shopify', $this->dataSource);

    expect($url)->toStartWith('https://connect.polybag.app/oauth/shopify/authorize?')
        ->and($url)->toContain('instance_id=test-instance')
        ->and($url)->toContain(urlencode('https://test.polybag.app'))
        ->and($url)->toContain('signature=')
        ->and($url)->toContain('shop=test-shop.myshopify.com');

    expect(session('oauth_state.shopify'))->not->toBeNull()
        ->and(strlen(session('oauth_state.shopify')))->toBe(40)
        ->and(session('oauth_data_source_id.shopify'))->toBe($this->dataSource->id);
});

it('throws when broker is not configured', function (): void {
    config([
        'services.oauth.broker_url' => null,
        'services.oauth.broker_secret' => null,
        'services.oauth.instance_id' => null,
    ]);

    app(OAuthService::class)->initiateAuthorizationForDataSource('shopify', $this->dataSource);
})->throws(RuntimeException::class, 'OAuth broker is not configured');

it('disconnects and clears a data source OAuth connection', function (): void {
    $this->dataSource->mergeSecret('oauth_access_token', 'token-to-remove');
    $settings = $this->dataSource->settings;
    $settings['auth_mode'] = 'authorization_code';
    $settings['oauth_scopes'] = 'read_orders';
    $settings['oauth_connected_at'] = now()->toIso8601String();
    $this->dataSource->settings = $settings;
    $this->dataSource->save();

    $oauthService = app(OAuthService::class);
    expect($oauthService->isDataSourceConnected($this->dataSource))->toBeTrue();

    $oauthService->disconnectDataSource('shopify', $this->dataSource);

    $this->dataSource->refresh();
    expect($oauthService->isDataSourceConnected($this->dataSource))->toBeFalse()
        ->and($this->dataSource->secret('oauth_access_token'))->toBeNull()
        ->and($this->dataSource->settings['auth_mode'] ?? null)->toBeNull()
        ->and($this->dataSource->settings['oauth_connected_at'] ?? null)->toBeNull();
});
