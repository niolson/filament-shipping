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
        Schema::table('pick_batches', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete()->after('user_id');
            $table->index('client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pick_batches', function (Blueprint $table) {
            $table->dropIndex(['client_id']);
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');
        });
    }
};
