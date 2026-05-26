<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_accounts', function (Blueprint $table) {
            $table->json('credentials')->nullable()->after('name');
            $table->text('secret_credentials')->nullable()->after('credentials');
        });

        // Data-migrate existing USPS columns into credentials JSON.
        DB::table('carrier_accounts')->lazyById()->each(function (object $row): void {
            $credentials = array_filter([
                'crid' => $row->usps_crid,
                'mid' => $row->usps_mid,
                'eps_account' => $row->usps_eps_account,
            ]);

            if ($credentials !== []) {
                DB::table('carrier_accounts')
                    ->where('id', $row->id)
                    ->update(['credentials' => json_encode($credentials)]);
            }
        });

        Schema::table('carrier_accounts', function (Blueprint $table) {
            $table->dropColumn(['usps_crid', 'usps_mid', 'usps_eps_account']);
        });
    }

    public function down(): void
    {
        Schema::table('carrier_accounts', function (Blueprint $table) {
            $table->string('usps_crid', 50)->nullable()->after('name');
            $table->string('usps_mid', 9)->nullable()->after('usps_crid');
            $table->string('usps_eps_account', 50)->nullable()->after('usps_mid');
        });

        // Restore USPS columns from credentials JSON.
        DB::table('carrier_accounts')->lazyById()->each(function (object $row): void {
            $credentials = json_decode($row->credentials ?? '{}', true) ?? [];

            DB::table('carrier_accounts')
                ->where('id', $row->id)
                ->update([
                    'usps_crid' => $credentials['crid'] ?? null,
                    'usps_mid' => $credentials['mid'] ?? null,
                    'usps_eps_account' => $credentials['eps_account'] ?? null,
                ]);
        });

        Schema::table('carrier_accounts', function (Blueprint $table) {
            $table->dropColumn(['credentials', 'secret_credentials']);
        });
    }
};
