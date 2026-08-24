<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Squashed migration: no-op on installs that ran the pre-squash history.
        if (Schema::hasTable('shipping_rules')) {
            return;
        }

        Schema::create('shipping_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->foreignId('shipping_method_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('priority')->default(0);
            $table->json('conditions')->nullable();
            $table->string('action');
            $table->foreignId('carrier_service_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index('priority');
            $table->index(['client_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rules');
    }
};
