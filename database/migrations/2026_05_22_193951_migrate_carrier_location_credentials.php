<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // For each carrier_location row that has USPS credentials, create a
        // CarrierAccount and a location-scoped CarrierAccountScope, then clear
        // old token caches that used the previous key format.
        $uspsId = DB::table('carriers')->where('name', 'USPS')->value('id');

        if ($uspsId) {
            $rows = DB::table('carrier_location')
                ->where('carrier_id', $uspsId)
                ->where(function ($q): void {
                    $q->whereNotNull('usps_crid')
                        ->orWhereNotNull('usps_mid')
                        ->orWhereNotNull('usps_eps_account');
                })
                ->get(['carrier_id', 'location_id', 'usps_crid', 'usps_mid', 'usps_eps_account']);

            foreach ($rows as $row) {
                $accountId = DB::table('carrier_accounts')->insertGetId([
                    'carrier_id' => $row->carrier_id,
                    'name' => 'USPS (migrated)',
                    'usps_crid' => $row->usps_crid,
                    'usps_mid' => $row->usps_mid,
                    'usps_eps_account' => $row->usps_eps_account,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('carrier_account_scopes')->insert([
                    'carrier_account_id' => $accountId,
                    'carrier_id' => $row->carrier_id,
                    'location_id' => $row->location_id,
                    'client_id' => null,
                    'rate_shop' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Clear old-format cache keys (previously keyed only by location_id).
                Cache::forget("usps_payment_authorization_token:{$row->location_id}");
            }

            Cache::forget('usps_payment_authorization_token:global');
        }

        Schema::table('carrier_location', function (Blueprint $table) {
            $table->dropColumn(['usps_crid', 'usps_mid', 'usps_eps_account']);
        });
    }

    public function down(): void
    {
        Schema::table('carrier_location', function (Blueprint $table) {
            $table->string('usps_crid', 50)->nullable();
            $table->string('usps_mid', 9)->nullable();
            $table->string('usps_eps_account', 50)->nullable();
        });
    }
};
