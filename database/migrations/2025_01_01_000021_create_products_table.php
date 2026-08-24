<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Squashed migration: no-op on installs that ran the pre-squash history.
        if (Schema::hasTable('products')) {
            return;
        }

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained();
            $table->string('name')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('barcode')->nullable()->index();
            $table->string('description')->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->decimal('handling_surcharge', 8, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('contains_alcohol')->default(false);
            $table->string('hazmat_class')->nullable();
            $table->string('hs_tariff_number')->nullable();
            $table->string('country_of_origin')->nullable();
            $table->string('bin_location')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
