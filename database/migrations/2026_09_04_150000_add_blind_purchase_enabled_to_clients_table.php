<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether this client has agreed to postage bought without a price or a
     * service. ADR-0003 decision 5.
     *
     * Deny by default, and deliberately not a setting: what is being consented
     * to is a purchase on this client's parcels at a price nobody sees until
     * afterwards, from a carrier chosen by somebody else. That consent belongs
     * to the party being billed for it, and a single-client install still has a
     * client — `shipments.client_id` is NOT NULL — so the column reaches every
     * install rather than only multi-client ones.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('blind_purchase_enabled')
                ->default(false)
                ->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('blind_purchase_enabled');
        });
    }
};
