<?php

use App\Filament\Pages\DevSettings;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
    app(SettingsService::class)->clearCache();
});

it('is not accessible in production', function (): void {
    $this->app['env'] = 'production';

    expect(DevSettings::canAccess())->toBeFalse();
});

it('is accessible in local environment', function (): void {
    expect(DevSettings::canAccess())->toBeTrue();
});

it('mounts sandbox_mode and suppress_printing from settings', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'dev']);
    Setting::create(['key' => 'suppress_printing', 'value' => '1', 'type' => 'boolean', 'group' => 'dev']);
    app(SettingsService::class)->clearCache();

    Livewire::test(DevSettings::class)
        ->assertSet('data.sandbox_mode', true)
        ->assertSet('data.suppress_printing', true);
});

it('mounts multi_client_enabled and multi_location_enabled from settings', function (): void {
    Setting::create(['key' => 'multi_client_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'dev']);
    Setting::create(['key' => 'multi_location_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'dev']);
    app(SettingsService::class)->clearCache();

    Livewire::test(DevSettings::class)
        ->assertSet('data.multi_client_enabled', true)
        ->assertSet('data.multi_location_enabled', true);
});

it('saves sandbox_mode setting', function (): void {
    Livewire::test(DevSettings::class)
        ->fillForm(['sandbox_mode' => true])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('sandbox_mode'))->toBeTrue();
});

it('saves suppress_printing setting when sandbox_mode is on', function (): void {
    Livewire::test(DevSettings::class)
        ->fillForm([
            'sandbox_mode' => true,
            'suppress_printing' => true,
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('suppress_printing'))->toBeTrue();
});

it('forces suppress_printing to false when sandbox_mode is turned off', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'dev']);
    Setting::create(['key' => 'suppress_printing', 'value' => '1', 'type' => 'boolean', 'group' => 'dev']);
    app(SettingsService::class)->clearCache();

    Livewire::test(DevSettings::class)
        ->fillForm(['sandbox_mode' => false])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('suppress_printing'))->toBeFalse();
});

it('saves multi_client_enabled and multi_location_enabled settings', function (): void {
    Livewire::test(DevSettings::class)
        ->fillForm([
            'multi_client_enabled' => true,
            'multi_location_enabled' => true,
        ])
        ->call('save')
        ->assertNotified();

    app(SettingsService::class)->clearCache();
    expect(app(SettingsService::class)->get('multi_client_enabled'))->toBeTrue()
        ->and(app(SettingsService::class)->get('multi_location_enabled'))->toBeTrue();
});

it('clears API auth caches when sandbox_mode changes', function (): void {
    Cache::put('usps_authenticator', 'test-token', 3600);
    Cache::put('usps_payment_authorization_token:global', 'test-payment-token-global', 3600);
    Cache::put('fedex_authenticator', 'test-fedex-token', 3600);

    Livewire::test(DevSettings::class)
        ->fillForm(['sandbox_mode' => true])
        ->call('save')
        ->assertNotified();

    expect(Cache::has('usps_authenticator'))->toBeFalse()
        ->and(Cache::has('usps_payment_authorization_token:global'))->toBeFalse()
        ->and(Cache::has('fedex_authenticator'))->toBeFalse();
});

it('does not clear API auth caches when sandbox_mode does not change', function (): void {
    Setting::create(['key' => 'sandbox_mode', 'value' => '1', 'type' => 'boolean', 'group' => 'dev']);
    app(SettingsService::class)->clearCache();

    Cache::put('usps_authenticator', 'test-token', 3600);
    Cache::put('fedex_authenticator', 'test-fedex-token', 3600);

    Livewire::test(DevSettings::class)
        ->fillForm(['sandbox_mode' => true])
        ->call('save')
        ->assertNotified();

    expect(Cache::has('usps_authenticator'))->toBeTrue()
        ->and(Cache::has('fedex_authenticator'))->toBeTrue();
});
