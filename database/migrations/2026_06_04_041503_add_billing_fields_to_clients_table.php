<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('pick_fee_first_item', 8, 2)->nullable()->after('export_import_source_id');
            $table->decimal('pick_fee_additional_item', 8, 2)->nullable()->after('pick_fee_first_item');
            $table->decimal('label_fee_per_package', 8, 2)->nullable()->after('pick_fee_additional_item');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['pick_fee_first_item', 'pick_fee_additional_item', 'label_fee_per_package']);
        });
    }
};
