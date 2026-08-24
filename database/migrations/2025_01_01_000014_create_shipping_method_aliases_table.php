<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_method_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained();
            $table->string('reference');
            $table->foreignId('shipping_method_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'reference'], 'shipping_method_aliases_client_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_method_aliases');
    }
};
