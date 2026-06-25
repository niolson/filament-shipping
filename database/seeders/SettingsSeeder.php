<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            // Company Info
            [
                'key' => 'company_name',
                'value' => config('app.name', 'Shipping Center'),
                'type' => 'string',
                'group' => 'company',
            ],

            // Feature Toggles
            [
                'key' => 'packing_validation_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'features',
            ],
            [
                'key' => 'transparency_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'features',
            ],

            // Carrier API
            [
                'key' => 'carrier_api_timeout',
                'value' => '15',
                'type' => 'integer',
                'group' => 'carrier',
            ],

            // Carrier and import credentials are managed on the CarrierAccount and
            // DataSource models, and the ship-from / return address on Location and
            // Client — they are intentionally not seeded here.

            // Testing
            [
                'key' => 'sandbox_mode',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'testing',
            ],
            [
                'key' => 'suppress_printing',
                'value' => config('app.fake_carriers', false) ? '1' : '0',
                'type' => 'boolean',
                'group' => 'testing',
            ],
            [
                'key' => 'setup_complete',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'system',
            ],
            [
                'key' => 'setup_wizard_step',
                'value' => '1',
                'type' => 'integer',
                'group' => 'system',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
