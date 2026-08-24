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
        // Squashed migration: no-op on installs that ran the pre-squash history.
        if (Schema::hasTable('data_source_locations')) {
            return;
        }
        Schema::create('data_source_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('external_code')->nullable();
            $table->string('name');
            $table->json('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('ignored_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['data_source_id', 'external_id']);
            $table->index(['data_source_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_source_locations');
    }
};
