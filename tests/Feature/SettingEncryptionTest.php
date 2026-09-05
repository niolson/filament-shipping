<?php

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

it('encrypts when value and encrypted flag are passed together to create', function (): void {
    // Regression: previously `value` was serialized/encrypted before the
    // `encrypted` flag was set, storing the secret in plaintext.
    Setting::create([
        'key' => 'secret.token',
        'value' => 'super-secret',
        'type' => 'string',
        'encrypted' => true,
    ]);

    $raw = DB::table('settings')->where('key', 'secret.token')->value('value');
    expect($raw)->not->toBe('super-secret');
    expect(Setting::find('secret.token')->value)->toBe('super-secret');
});

it('encrypts a setting created for the first time via SettingsService', function (): void {
    app(SettingsService::class)->set('oauth.access_token', 'tok-abc', 'string', encrypted: true);

    $raw = DB::table('settings')->where('key', 'oauth.access_token')->value('value');
    expect($raw)->not->toBe('tok-abc');
    expect(Setting::find('oauth.access_token')->value)->toBe('tok-abc');
});

it('serializes json when value precedes type in create', function (): void {
    // The same ordering hazard affected type-based serialization.
    Setting::create([
        'key' => 'cfg.json',
        'value' => ['a' => 1, 'b' => 'two'],
        'type' => 'json',
    ]);

    expect(Setting::find('cfg.json')->value)->toBe(['a' => 1, 'b' => 'two']);
});

it('reflects a staged value before it is saved', function (): void {
    $setting = new Setting(['key' => 'k', 'type' => 'string', 'encrypted' => true]);
    $setting->value = 'pending';

    expect($setting->value)->toBe('pending');
});
