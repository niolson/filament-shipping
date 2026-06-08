<?php

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(SettingsService::class)->clearCache();
});

it('returns default value when setting does not exist', function (): void {
    $result = app(SettingsService::class)->get('nonexistent_key', 'default_value');

    expect($result)->toBe('default_value');
});

it('returns null when setting does not exist and no default provided', function (): void {
    $result = app(SettingsService::class)->get('nonexistent_key');

    expect($result)->toBeNull();
});

it('retrieves string setting values', function (): void {
    Setting::create([
        'key' => 'test_string',
        'value' => 'hello world',
        'type' => 'string',
    ]);

    $result = app(SettingsService::class)->get('test_string');

    expect($result)->toBe('hello world');
});

it('retrieves boolean setting values', function (): void {
    Setting::create([
        'key' => 'test_bool_true',
        'value' => '1',
        'type' => 'boolean',
    ]);

    Setting::create([
        'key' => 'test_bool_false',
        'value' => '0',
        'type' => 'boolean',
    ]);

    expect(app(SettingsService::class)->get('test_bool_true'))->toBeTrue()
        ->and(app(SettingsService::class)->get('test_bool_false'))->toBeFalse();
});

it('retrieves integer setting values', function (): void {
    Setting::create([
        'key' => 'test_integer',
        'value' => '42',
        'type' => 'integer',
    ]);

    $result = app(SettingsService::class)->get('test_integer');

    expect($result)->toBe(42)
        ->and($result)->toBeInt();
});

it('retrieves json setting values', function (): void {
    Setting::create([
        'key' => 'test_json',
        'value' => '{"foo":"bar","count":5}',
        'type' => 'json',
    ]);

    $result = app(SettingsService::class)->get('test_json');

    expect($result)->toBeArray()
        ->and($result['foo'])->toBe('bar')
        ->and($result['count'])->toBe(5);
});

it('creates a new setting with set method', function (): void {
    app(SettingsService::class)->set('new_setting', 'new_value', 'string', false, 'test_group');

    $setting = Setting::find('new_setting');

    expect($setting)->not->toBeNull()
        ->and($setting->value)->toBe('new_value')
        ->and($setting->type)->toBe('string')
        ->and($setting->group)->toBe('test_group');
});

it('updates an existing setting with set method', function (): void {
    Setting::create([
        'key' => 'existing_setting',
        'value' => 'original',
        'type' => 'string',
    ]);

    app(SettingsService::class)->set('existing_setting', 'updated');

    $setting = Setting::find('existing_setting');

    expect($setting->value)->toBe('updated');
});

it('updates multiple settings with setMany', function (): void {
    Setting::create(['key' => 'setting_1', 'value' => 'a', 'type' => 'string']);
    Setting::create(['key' => 'setting_2', 'value' => 'b', 'type' => 'string']);

    app(SettingsService::class)->setMany([
        'setting_1' => 'updated_a',
        'setting_2' => 'updated_b',
    ]);

    expect(Setting::find('setting_1')->value)->toBe('updated_a')
        ->and(Setting::find('setting_2')->value)->toBe('updated_b');
});

it('caches settings and returns cached values', function (): void {
    Setting::create([
        'key' => 'cached_setting',
        'value' => 'cached_value',
        'type' => 'string',
    ]);

    // First call should cache
    $result1 = app(SettingsService::class)->get('cached_setting');

    // Delete directly from DB (bypassing service)
    Setting::where('key', 'cached_setting')->delete();

    // Should still return cached value
    $result2 = app(SettingsService::class)->get('cached_setting');

    expect($result1)->toBe('cached_value')
        ->and($result2)->toBe('cached_value');
});

it('clears cache when clearCache is called', function (): void {
    Setting::create([
        'key' => 'cached_setting',
        'value' => 'original',
        'type' => 'string',
    ]);

    // Cache the setting
    app(SettingsService::class)->get('cached_setting');

    // Update directly in DB
    Setting::where('key', 'cached_setting')->update(['value' => 'updated']);

    // Clear cache
    app(SettingsService::class)->clearCache();

    // Should now return updated value
    $result = app(SettingsService::class)->get('cached_setting');

    expect($result)->toBe('updated');
});

// ─── Environment-based overrides ─────────────────────────────────────────────

it('forces sandbox_mode to false in production regardless of DB value', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'dev']);
    app(SettingsService::class)->clearCache();

    $this->app['env'] = 'production';

    expect(app(SettingsService::class)->get('sandbox_mode'))->toBeFalse();
});

it('forces sandbox_mode to true in demo mode regardless of DB value', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '0', 'type' => 'boolean', 'group' => 'dev']);
    app(SettingsService::class)->clearCache();

    $this->app['env'] = 'demo';

    expect(app(SettingsService::class)->get('sandbox_mode'))->toBeTrue();
});

it('reads sandbox_mode from DB in local environment', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'dev']);
    app(SettingsService::class)->clearCache();

    expect(app(SettingsService::class)->get('sandbox_mode'))->toBeTrue();
});

it('forces suppress_printing to false in production', function (): void {
    Setting::create(['key' => 'suppress_printing', 'value' => '1', 'type' => 'boolean', 'group' => 'dev']);
    app(SettingsService::class)->clearCache();

    $this->app['env'] = 'production';

    expect(app(SettingsService::class)->get('suppress_printing'))->toBeFalse();
});

it('reads multi_client_enabled from config in non-local environments', function (): void {
    Setting::create(['key' => 'multi_client_enabled', 'value' => '0', 'type' => 'boolean', 'group' => 'dev']);
    app(SettingsService::class)->clearCache();

    config(['features.multi_client_enabled' => true]);
    $this->app['env'] = 'production';

    expect(app(SettingsService::class)->get('multi_client_enabled'))->toBeTrue();
});

it('reads multi_client_enabled from DB in local environment', function (): void {
    config(['features.multi_client_enabled' => false]);
    Setting::create(['key' => 'multi_client_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'dev']);
    app(SettingsService::class)->clearCache();

    expect(app(SettingsService::class)->get('multi_client_enabled'))->toBeTrue();
});

it('reads multi_location_enabled from config in non-local environments', function (): void {
    Setting::create(['key' => 'multi_location_enabled', 'value' => '0', 'type' => 'boolean', 'group' => 'dev']);
    app(SettingsService::class)->clearCache();

    config(['features.multi_location_enabled' => true]);
    $this->app['env'] = 'production';

    expect(app(SettingsService::class)->get('multi_location_enabled'))->toBeTrue();
});

it('isDemoMode returns true when APP_ENV is demo', function (): void {
    $this->app['env'] = 'demo';

    expect(SettingsService::isDemoMode())->toBeTrue();
});

it('isDemoMode returns false when APP_ENV is not demo', function (): void {
    expect(SettingsService::isDemoMode())->toBeFalse();
});
