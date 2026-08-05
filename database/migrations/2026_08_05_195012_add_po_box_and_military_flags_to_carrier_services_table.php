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
        Schema::table('carrier_services', function (Blueprint $table) {
            $table->boolean('can_ship_to_po_boxes')->default(false);
            $table->boolean('can_ship_to_military_addresses')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_services', function (Blueprint $table) {
            $table->dropColumn(['can_ship_to_po_boxes', 'can_ship_to_military_addresses']);
        });
    }
};
