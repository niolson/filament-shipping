<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Squashed migration: no-op on installs that ran the pre-squash history.
        if (Schema::hasTable('pick_batch_shipments')) {
            return;
        }

        Schema::create('pick_batch_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pick_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('tote_code')->nullable();
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('pack_slip_printed_at')->nullable();
            $table->timestamps();

            $table->unique(['pick_batch_id', 'shipment_id']);
            $table->index('tote_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pick_batch_shipments');
    }
};
