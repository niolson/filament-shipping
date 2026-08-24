<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Squashed migration: no-op on installs that ran the pre-squash history.
        if (Schema::hasTable('carrier_services')) {
            return;
        }

        Schema::create('carrier_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $table->string('service_code');
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->boolean('can_ship_to_po_boxes')->default(false);
            $table->boolean('can_ship_to_military_addresses')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_services');
    }
};
