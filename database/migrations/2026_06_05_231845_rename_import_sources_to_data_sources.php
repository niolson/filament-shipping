<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_sources', function (Blueprint $table) {
            $table->boolean('global_export')->default(false)->after('active');
        });

        Schema::rename('import_sources', 'data_sources');

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['import_source_id']);
            $table->dropUnique('shipments_import_source_record_unique');
            $table->renameColumn('import_source_id', 'data_source_id');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreign('data_source_id')->references('id')->on('data_sources')->nullOnDelete();
            $table->unique(['data_source_id', 'source_record_id'], 'shipments_data_source_record_unique');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['export_import_source_id']);
            $table->renameColumn('export_import_source_id', 'export_data_source_id');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreign('export_data_source_id')->references('id')->on('data_sources')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['export_data_source_id']);
            $table->renameColumn('export_data_source_id', 'export_import_source_id');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['data_source_id']);
            $table->dropUnique('shipments_data_source_record_unique');
            $table->renameColumn('data_source_id', 'import_source_id');
        });

        Schema::rename('data_sources', 'import_sources');

        Schema::table('clients', function (Blueprint $table) {
            $table->foreign('export_import_source_id')->references('id')->on('import_sources')->nullOnDelete();
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreign('import_source_id')->references('id')->on('import_sources')->nullOnDelete();
            $table->unique(['import_source_id', 'source_record_id'], 'shipments_import_source_record_unique');
        });

        Schema::table('import_sources', function (Blueprint $table) {
            $table->dropColumn('global_export');
        });
    }
};
