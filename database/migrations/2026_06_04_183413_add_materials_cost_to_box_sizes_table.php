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
        Schema::table('box_sizes', function (Blueprint $table) {
            $table->decimal('materials_cost', 8, 2)->nullable()->after('empty_weight');
        });
    }

    public function down(): void
    {
        Schema::table('box_sizes', function (Blueprint $table) {
            $table->dropColumn('materials_cost');
        });
    }
};
