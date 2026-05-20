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
        Schema::table('pick_batch_shipments', function (Blueprint $table): void {
            $table->timestamp('pack_slip_printed_at')->nullable()->after('picked_at');
        });
    }

    public function down(): void
    {
        Schema::table('pick_batch_shipments', function (Blueprint $table): void {
            $table->dropColumn('pack_slip_printed_at');
        });
    }
};
