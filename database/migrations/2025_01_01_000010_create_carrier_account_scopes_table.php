<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Squashed migration: no-op on installs that ran the pre-squash history.
        if (Schema::hasTable('carrier_account_scopes')) {
            return;
        }
        Schema::create('carrier_account_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_account_id')->constrained()->cascadeOnDelete();
            // carrier_id is denormalized here so the unique constraint can enforce
            // "only one account per (carrier, location, client) combination" at the DB level.
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            // Generated columns replace NULL with 0 so MySQL's unique index treats
            // nulls as equal (MySQL normally treats each NULL as distinct).
            $table->unsignedBigInteger('location_key')->virtualAs('COALESCE(location_id, 0)');
            $table->unsignedBigInteger('client_key')->virtualAs('COALESCE(client_id, 0)');
            $table->boolean('rate_shop')->default(false);
            $table->timestamps();

            $table->unique(['carrier_id', 'location_key', 'client_key'], 'carrier_account_scopes_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_account_scopes');
    }
};
