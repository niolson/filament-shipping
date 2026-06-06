<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['export_data_source_id']);
            $table->dropColumn('export_data_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('export_data_source_id')
                ->nullable()
                ->constrained('data_sources')
                ->nullOnDelete();
        });
    }
};
