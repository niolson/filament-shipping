<?php

use App\Filament\AvatarProviders\LocalAvatarProvider;
use App\Models\User;

it('generates a local data-uri svg avatar with the user initials', function () {
    $user = User::factory()->make(['name' => 'Ada Lovelace']);

    $uri = app(LocalAvatarProvider::class)->get($user);

    expect($uri)->toStartWith('data:image/svg+xml;base64,');

    $svg = base64_decode(str($uri)->after('base64,')->toString());

    expect($svg)->toContain('<svg')
        ->and($svg)->toContain('>AL<')      // initials, uppercased, first two words
        ->and($uri)->not->toContain('ui-avatars.com');
});

it('falls back to a placeholder when no name is present', function () {
    $user = User::factory()->make(['name' => '']);

    $svg = base64_decode(
        str(app(LocalAvatarProvider::class)->get($user))->after('base64,')->toString()
    );

    expect($svg)->toContain('>?<');
});
