<?php

use App\Enums\Role;
use App\Filament\Resources\ImportSources\ImportSourceResource;
use App\Filament\Resources\ImportSources\Pages\CreateImportSource;
use App\Filament\Resources\ImportSources\Pages\EditImportSource;
use App\Models\ImportSource;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

// ── Access control ────────────────────────────────────────────────────────────

it('blocks non-admin users from accessing import sources', function (): void {
    $user = User::factory()->create(['role' => Role::User]);
    $this->actingAs($user);

    expect(ImportSourceResource::canAccess())->toBeFalse();
});

it('allows admin users to access import sources', function (): void {
    $this->actingAs($this->admin);

    expect(ImportSourceResource::canAccess())->toBeTrue();
});

// ── Secret settings encryption ────────────────────────────────────────────────

it('routes secret keys to encrypted secret_settings on create', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateImportSource::class)
        ->fillForm([
            'name' => 'Shopify Test',
            'config_key' => 'shopify_test',
            'driver' => ShopifySource::class,
            'settings.shop_domain' => 'test.myshopify.com',
            'settings.access_token' => 'shpat_secret_token',
            'settings.client_id' => 'secret_client_id',
            'settings.client_secret' => 'secret_client_secret',
            'settings.channel_name' => 'Shopify',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $record = ImportSource::where('config_key', 'shopify_test')->firstOrFail();

    // Secrets must be in encrypted column, not plain settings
    expect($record->settings)->not->toHaveKey('access_token');
    expect($record->settings)->not->toHaveKey('client_id');
    expect($record->settings)->not->toHaveKey('client_secret');

    expect($record->secret('access_token'))->toBe('shpat_secret_token');
    expect($record->secret('client_id'))->toBe('secret_client_id');
    expect($record->secret('client_secret'))->toBe('secret_client_secret');
});

it('routes db_password to secret_settings on create', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateImportSource::class)
        ->fillForm([
            'name' => 'DB Source',
            'config_key' => 'db_test',
            'driver' => DatabaseSource::class,
            'settings.db_host' => 'localhost',
            'settings.db_database' => 'orders',
            'settings.db_username' => 'reader',
            'settings.db_password' => 'supersecret',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $record = ImportSource::where('config_key', 'db_test')->firstOrFail();

    expect($record->settings)->not->toHaveKey('db_password');
    expect($record->secret('db_password'))->toBe('supersecret');
});

it('preserves existing secrets when a blank password is submitted on edit', function (): void {
    $this->actingAs($this->admin);

    $source = ImportSource::factory()->shopify()->create([
        'config_key' => 'shopify_edit',
        'secret_settings' => ['access_token' => 'original_token'],
    ]);

    Livewire::test(EditImportSource::class, ['record' => $source->id])
        ->fillForm([
            'name' => 'Updated Name',
            'settings.shop_domain' => 'test.myshopify.com',
            'settings.access_token' => null,
            'settings.channel_name' => 'Shopify',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $source->refresh();
    expect($source->secret('access_token'))->toBe('original_token');
});

it('replaces a secret when a new value is submitted on edit', function (): void {
    $this->actingAs($this->admin);

    $source = ImportSource::factory()->shopify()->create([
        'config_key' => 'shopify_replace',
        'secret_settings' => ['access_token' => 'old_token'],
    ]);

    Livewire::test(EditImportSource::class, ['record' => $source->id])
        ->fillForm([
            'name' => 'Updated Name',
            'settings.shop_domain' => 'test.myshopify.com',
            'settings.access_token' => 'new_token',
            'settings.channel_name' => 'Shopify',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $source->refresh();
    expect($source->secret('access_token'))->toBe('new_token');
});

it('migrates legacy plaintext secrets from settings to secret_settings on edit', function (): void {
    $this->actingAs($this->admin);

    // Simulate a record saved before the encrypted column was introduced
    $source = ImportSource::factory()->shopify()->create([
        'config_key' => 'shopify_legacy',
        'settings' => [
            'shop_domain' => 'test.myshopify.com',
            'channel_name' => 'Shopify',
            'access_token' => 'legacy_plaintext_token',
        ],
    ]);

    Livewire::test(EditImportSource::class, ['record' => $source->id])
        ->fillForm([
            'name' => 'Legacy Source',
            'settings.shop_domain' => 'test.myshopify.com',
            'settings.access_token' => null,
            'settings.channel_name' => 'Shopify',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $source->refresh();
    expect($source->settings)->not->toHaveKey('access_token');
    expect($source->secret('access_token'))->toBe('legacy_plaintext_token');
});

// ── ShopifySource validation ──────────────────────────────────────────────────

it('validates when oauth_access_token is present even without tenant credentials', function (): void {
    $source = new ShopifySource([
        'config_key' => 'shopify_oauth',
        'shop_domain' => 'test.myshopify.com',
        'oauth_access_token' => 'shpat_oauth_token',
        'channel_name' => 'Shopify',
    ]);

    // Should not throw — oauth token satisfies the credentials requirement
    $source->validateConfiguration();
    expect(true)->toBeTrue();
});

it('validates when per-source client_id and client_secret are both present', function (): void {
    $source = new ShopifySource([
        'config_key' => 'shopify_per_source',
        'shop_domain' => 'test.myshopify.com',
        'client_id' => 'per_source_id',
        'client_secret' => 'per_source_secret',
        'channel_name' => 'Shopify',
    ]);

    $source->validateConfiguration();
    expect(true)->toBeTrue();
});

it('fails validation when neither token nor credentials exist and no tenant credentials', function (): void {
    // Clear the global Shopify settings seeded in Pest.php beforeEach
    Setting::where('group', 'shopify')->delete();
    app(SettingsService::class)->clearCache();

    $source = new ShopifySource([
        'config_key' => 'shopify_empty',
        'shop_domain' => 'test.myshopify.com',
        'channel_name' => 'Shopify',
    ]);

    expect(fn () => $source->validateConfiguration())->toThrow(InvalidArgumentException::class, 'client ID');
});
