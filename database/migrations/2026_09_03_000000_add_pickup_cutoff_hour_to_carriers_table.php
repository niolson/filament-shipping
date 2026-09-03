<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('carriers', function (Blueprint $table) {
            $table->unsignedTinyInteger('pickup_cutoff_hour')
                ->nullable()
                ->after('name');
        });

        // Carry the USPS cutoff that lived in ShipDateService onto the carrier row
        // it belongs to. Matching by name is right *here* and nowhere else: a
        // migration acts on the data as it stands the moment it runs.
        $uspsCarrierIds = DB::table('carriers')
            ->whereRaw('LOWER(TRIM(name)) = ?', ['usps'])
            ->pluck('id')
            ->merge(DB::table('carrier_aliases')->where('lookup_key', 'usps')->pluck('carrier_id'))
            ->unique();

        if ($uspsCarrierIds->isNotEmpty()) {
            DB::table('carriers')
                ->whereIn('id', $uspsCarrierIds)
                ->update(['pickup_cutoff_hour' => 20]); // 8 PM local time
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carriers', function (Blueprint $table) {
            $table->dropColumn('pickup_cutoff_hour');
        });
    }
};
