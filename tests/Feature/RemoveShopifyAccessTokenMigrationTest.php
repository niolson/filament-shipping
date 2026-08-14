<?php

use App\Models\DataSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function removeShopifyAccessTokenMigration(): object
{
    return require database_path('migrations/2026_08_13_045847_remove_shopify_access_token_from_data_sources.php');
}

it('drops the deprecated access token from both settings columns', function (): void {
    $source = DataSource::factory()->create([
        'source_type' => ShopifySource::class,
        'settings' => [
            'shop_domain' => 'test.myshopify.com',
            'channel_name' => 'Shopify',
            'access_token' => 'legacy_plaintext_token',
        ],
        'secret_settings' => [
            'access_token' => 'legacy_encrypted_token',
            'client_secret' => 'kept_secret',
        ],
    ]);

    removeShopifyAccessTokenMigration()->up();

    $source->refresh();

    expect($source->settings)->not->toHaveKey('access_token')
        ->and($source->settings)->toHaveKey('shop_domain')
        ->and($source->secret('access_token'))->toBeNull()
        ->and($source->secret('client_secret'))->toBe('kept_secret');
});

it('leaves a Shopify source without a stored access token untouched', function (): void {
    $source = DataSource::factory()->shopify()->create([
        'secret_settings' => ['oauth_access_token' => 'shpat_oauth_token'],
    ]);
    $updatedAt = $source->updated_at;

    removeShopifyAccessTokenMigration()->up();

    $source->refresh();

    expect($source->secret('oauth_access_token'))->toBe('shpat_oauth_token')
        ->and($source->updated_at->eq($updatedAt))->toBeTrue();
});
