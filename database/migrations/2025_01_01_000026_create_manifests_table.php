<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Squashed migration: no-op on installs that ran the pre-squash history.
        if (Schema::hasTable('manifests')) {
            return;
        }

        Schema::create('manifests', function (Blueprint $table) {
            $table->id();
            $table->string('carrier');
            $table->string('manifest_number');
            $table->longText('image')->nullable();
            $table->date('manifest_date');
            $table->unsignedInteger('package_count');
            $table->timestamps();
            // Trails the timestamps because the migration that added it chained
            // ->after('carrier') onto constrained(), where Laravel ignores it.
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();

            $table->index(['carrier', 'manifest_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifests');
    }
};
