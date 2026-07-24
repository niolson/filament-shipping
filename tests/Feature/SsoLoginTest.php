<?php

use App\Models\User;
use App\Services\OAuthService;
use App\Services\SettingsService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Azure\User as AzureUser;

beforeEach(function (): void {
    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => str_repeat('ab', 32),
        'services.oauth.instance_id' => 'test-instance',
        'services.oauth.bypass_broker' => false,
        'app.url' => 'https://test.polybag.app',
    ]);

    $settings = app(SettingsService::class);
    $settings->set('google_sso_enabled', true, 'boolean', group: 'system');
    $settings->set('azure_sso_enabled', true, 'boolean', group: 'system');
    $settings->clearCache();
});

it('logs the IdP amr assertion on SSO login', function (): void {
    $user = User::factory()->create(['email' => 'sso-user@example.com', 'active' => true]);
    $nonce = 'valid-sso-nonce';

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'google',
            'access_token' => 'google-access-token',
            'nonce' => $nonce,
            'extra' => [
                'user_email' => 'sso-user@example.com',
                'amr' => ['mfa', 'pwd'],
                'auth_time' => 1748875426,
            ],
        ]),
    ]);

    $logged = [];
    Log::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message;
    });

    $this->withSession(['oauth_state.google' => $nonce])
        ->get('/auth/sso/google/receive?transfer_code=abc123')
        ->assertRedirect('/');

    $assertion = collect($logged)->first(
        fn (MessageLogged $message): bool => str_contains($message->message, 'MFA assertion'),
    );

    expect($assertion)->not->toBeNull()
        ->and($assertion->context['amr'])->toBe(['mfa', 'pwd'])
        ->and($assertion->context['auth_time'])->toBe(1748875426);
});

it('logs in a user via the broker SSO receive flow', function (): void {
    $user = User::factory()->create(['email' => 'sso-user@example.com', 'active' => true]);
    $nonce = 'valid-sso-nonce';

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'azure',
            'access_token' => 'graph-access-token',
            'nonce' => $nonce,
            'extra' => ['user_email' => 'sso-user@example.com', 'user_name' => 'SSO User'],
        ]),
    ]);

    $response = $this->withSession(['oauth_state.azure' => $nonce])
        ->get('/auth/sso/azure/receive?transfer_code=abc123');

    $response->assertRedirect('/');
    expect(auth()->id())->toBe($user->id);
});

it('rejects an SSO receive with a mismatched nonce', function (): void {
    User::factory()->create(['email' => 'sso-user@example.com']);

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'azure',
            'access_token' => 'graph-access-token',
            'nonce' => 'broker-nonce',
            'extra' => ['user_email' => 'sso-user@example.com'],
        ]),
    ]);

    $response = $this->withSession(['oauth_state.azure' => 'different-nonce'])
        ->get('/auth/sso/azure/receive?transfer_code=abc123');

    $response->assertRedirect('/login');
    expect(auth()->check())->toBeFalse();
});

it('rejects an SSO login for an unknown email', function (): void {
    $nonce = 'valid-sso-nonce';

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'google',
            'access_token' => 'google-access-token',
            'nonce' => $nonce,
            'extra' => ['user_email' => 'nobody@example.com'],
        ]),
    ]);

    $response = $this->withSession(['oauth_state.google' => $nonce])
        ->get('/auth/sso/google/receive?transfer_code=abc123');

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('data.login');
    expect(auth()->check())->toBeFalse();
});

it('rejects an SSO login for a disabled user', function (): void {
    User::factory()->create(['email' => 'disabled@example.com', 'active' => false]);
    $nonce = 'valid-sso-nonce';

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'google',
            'access_token' => 'google-access-token',
            'nonce' => $nonce,
            'extra' => ['user_email' => 'disabled@example.com'],
        ]),
    ]);

    $response = $this->withSession(['oauth_state.google' => $nonce])
        ->get('/auth/sso/google/receive?transfer_code=abc123');

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('data.login');
    expect(auth()->check())->toBeFalse();
});

it('rejects an SSO login when the broker omits the email', function (): void {
    $nonce = 'valid-sso-nonce';

    Http::fake([
        'connect.polybag.app/oauth/claim' => Http::response([
            'provider' => 'google',
            'access_token' => 'google-access-token',
            'nonce' => $nonce,
            'extra' => [],
        ]),
    ]);

    $response = $this->withSession(['oauth_state.google' => $nonce])
        ->get('/auth/sso/google/receive?transfer_code=abc123');

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('data.login');
    expect(auth()->check())->toBeFalse();
});

it('handles an error redirect from the broker', function (): void {
    Http::fake();

    $response = $this->get('/auth/sso/azure/receive?error=access_denied&error_description=User+denied+access');

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('data.login');
    expect(auth()->check())->toBeFalse();
    Http::assertNothingSent();
});

it('throttles the SSO receive route to protect the broker from hammering', function (): void {
    Http::fake();

    // The error path returns before any broker call, so this exercises the
    // route's throttle cheaply. The 21st request within the window is rejected.
    for ($i = 0; $i < 20; $i++) {
        $this->get('/auth/sso/azure/receive?error=access_denied')->assertRedirect('/login');
    }

    $this->get('/auth/sso/azure/receive?error=access_denied')->assertStatus(429);
});

it('rejects an SSO receive when the provider is disabled', function (): void {
    app(SettingsService::class)->set('azure_sso_enabled', false, 'boolean', group: 'system');
    app(SettingsService::class)->clearCache();

    Http::fake();

    $response = $this->withSession(['oauth_state.azure' => 'nonce'])
        ->get('/auth/sso/azure/receive?transfer_code=abc123');

    $response->assertRedirect('/login');
    expect(auth()->check())->toBeFalse();
    Http::assertNothingSent();
});

it('builds a broker SSO authorization URL with a signed return_path', function (): void {
    $url = app(OAuthService::class)->initiateSsoAuthorization('azure');

    expect($url)->toStartWith('https://connect.polybag.app/oauth/azure/authorize?');
    expect($url)->toContain('instance_id=test-instance');
    expect($url)->toContain('return_path='.urlencode('/auth/sso/azure/receive'));
    expect($url)->toContain('signature=');

    $nonce = session('oauth_state.azure');
    expect($nonce)->not->toBeNull();

    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $expected = hash_hmac(
        'sha256',
        "azure:test-instance:https://test.polybag.app:/auth/sso/azure/receive:{$nonce}",
        str_repeat('ab', 32),
    );
    expect($query['signature'])->toBe($expected);
});

it('redirects SSO login start through the broker when configured', function (): void {
    $response = $this->get(route('auth.azure.redirect'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toStartWith('https://connect.polybag.app/oauth/azure/authorize?');
});

it('shows a configuration error instead of 500 when the broker is partially configured', function (): void {
    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => '',
        'services.oauth.instance_id' => '',
    ]);

    Http::fake();

    $response = $this->get(route('auth.azure.redirect'));

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('data.login');
    Http::assertNothingSent();
});

it('prefers the Azure mailbox address over userPrincipalName on the direct callback', function (): void {
    config(['services.oauth.bypass_broker' => true]);

    $user = User::factory()->create(['email' => 'mailbox@example.com', 'active' => true]);

    $azureUser = new AzureUser;
    $azureUser->map([
        'email' => 'upn@example.onmicrosoft.com',
        'mail' => 'mailbox@example.com',
    ]);

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->once()->andReturn($azureUser);
    Socialite::shouldReceive('driver')->with('azure')->andReturn($provider);

    $response = $this->get(route('auth.azure.callback'));

    $response->assertRedirect('/');
    expect(auth()->id())->toBe($user->id);
});

it('falls back to userPrincipalName when the Azure mailbox address is absent', function (): void {
    config(['services.oauth.bypass_broker' => true]);

    $user = User::factory()->create(['email' => 'upn@example.onmicrosoft.com', 'active' => true]);

    $azureUser = new AzureUser;
    $azureUser->map([
        'email' => 'upn@example.onmicrosoft.com',
        'mail' => null,
    ]);

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->once()->andReturn($azureUser);
    Socialite::shouldReceive('driver')->with('azure')->andReturn($provider);

    $response = $this->get(route('auth.azure.callback'));

    $response->assertRedirect('/');
    expect(auth()->id())->toBe($user->id);
});

it('bypasses the broker for SSO when OAUTH_BYPASS_BROKER is set', function (): void {
    config(['services.oauth.bypass_broker' => true]);
    config([
        'services.azure.client_id' => 'azure-client-id',
        'services.azure.client_secret' => 'azure-client-secret',
        'services.azure.redirect' => 'https://test.polybag.app/auth/azure/callback',
    ]);

    $response = $this->get(route('auth.azure.redirect'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toContain('login.microsoftonline.com');
});
