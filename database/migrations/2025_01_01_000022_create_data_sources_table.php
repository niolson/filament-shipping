<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Squashed migration: no-op on installs that ran the pre-squash history.
        if (Schema::hasTable('data_sources')) {
            return;
        }

        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('name');
            $table->string('source_type');
            $table->boolean('active')->default(true);
            $table->boolean('global_export')->default(false);
            $table->string('schedule_interval')->nullable();
            $table->json('settings')->nullable();
            $table->longText('secret_settings')->nullable();
            $table->timestamps();

            // This table was created as `import_sources` and renamed in place, so
            // existing installs carry the old index and constraint names. Name them
            // explicitly here so a fresh install matches an upgraded one exactly.
            $table->index('client_id', 'import_sources_client_id_index');
            $table->foreign('client_id', 'import_sources_client_id_foreign')
                ->references('id')->on('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};
