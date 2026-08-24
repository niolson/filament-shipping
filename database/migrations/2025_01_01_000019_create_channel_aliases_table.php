<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Squashed migration: no-op on installs that ran the pre-squash history.
        if (Schema::hasTable('channel_aliases')) {
            return;
        }

        Schema::create('channel_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained();
            $table->string('reference');
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'reference'], 'channel_aliases_client_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_aliases');
    }
};
