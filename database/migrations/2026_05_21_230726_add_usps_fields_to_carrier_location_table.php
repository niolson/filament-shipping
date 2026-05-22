<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('carrier_location', function (Blueprint $table) {
            $table->string('usps_crid', 50)->nullable()->after('last_end_of_day_at');
            $table->string('usps_mid', 9)->nullable()->after('usps_crid');
            $table->string('usps_eps_account', 50)->nullable()->after('usps_mid');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_location', function (Blueprint $table) {
            $table->dropColumn(['usps_crid', 'usps_mid', 'usps_eps_account']);
        });
    }
};
