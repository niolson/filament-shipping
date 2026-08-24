<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Squashed migration: no-op on installs that ran the pre-squash history.
        if (Schema::hasTable('carrier_service_special_service')) {
            return;
        }
        Schema::create('carrier_service_special_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('special_service_id')->constrained()->cascadeOnDelete();
            $table->json('restricted_countries')->nullable(); // ISO country codes; null = unrestricted
            $table->timestamps();

            $table->unique(['carrier_service_id', 'special_service_id'], 'cs_ss_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_service_special_service');
    }
};
