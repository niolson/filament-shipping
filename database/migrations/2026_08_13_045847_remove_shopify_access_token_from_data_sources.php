<?php

use App\Models\DataSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The Shopify custom-app access token is deprecated; the connector now
     * authenticates with the per-source OAuth token or client credentials only.
     * Drop the stored value from both the plaintext and encrypted columns so a
     * dead credential is not left sitting in the database.
     */
    public function up(): void
    {
        DataSource::query()
            ->where('source_type', ShopifySource::class)
            ->each(function (DataSource $source): void {
                $settings = $source->settings ?? [];
                $secrets = $source->secret_settings ?? [];

                if (! array_key_exists('access_token', $settings) && ! array_key_exists('access_token', $secrets)) {
                    return;
                }

                unset($settings['access_token'], $secrets['access_token']);

                $source->settings = $settings;
                $source->secret_settings = $secrets ?: null;
                $source->save();
            });
    }

    /**
     * Not reversible — the discarded tokens cannot be recovered.
     */
    public function down(): void {}
};
