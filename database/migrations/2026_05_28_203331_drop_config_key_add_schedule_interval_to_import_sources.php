<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL uses the composite unique as the supporting index for the client_id FK.
        // Add a plain index first so the FK has a replacement before we drop the composite.
        Schema::table('import_sources', function (Blueprint $table): void {
            $table->index('client_id', 'import_sources_client_id_index');
        });

        Schema::table('import_sources', function (Blueprint $table): void {
            $table->dropUnique('import_sources_client_config_unique');
            $table->dropColumn('config_key');
            $table->string('schedule_interval')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('import_sources', function (Blueprint $table): void {
            $table->dropColumn('schedule_interval');
            $table->string('config_key')->nullable();
            $table->unique(['client_id', 'config_key'], 'import_sources_client_config_unique');
        });

        Schema::table('import_sources', function (Blueprint $table): void {
            $table->dropIndex('import_sources_client_id_index');
        });
    }
};
