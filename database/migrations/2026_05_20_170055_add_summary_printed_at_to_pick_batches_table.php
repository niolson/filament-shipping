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
        Schema::table('pick_batches', function (Blueprint $table): void {
            $table->timestamp('summary_printed_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('pick_batches', function (Blueprint $table): void {
            $table->dropColumn('summary_printed_at');
        });
    }
};
