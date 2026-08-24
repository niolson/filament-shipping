<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Squashed migration: no-op on installs that ran the pre-squash history.
        if (Schema::hasTable('pick_batches')) {
            return;
        }

        Schema::create('pick_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('status')->default('in_progress');
            $table->unsignedInteger('total_shipments')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('summary_printed_at')->nullable();
            $table->timestamps();
            // Trails the timestamps because the migration that added it chained
            // ->after('user_id') onto constrained(), where Laravel ignores it.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            $table->index('status');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pick_batches');
    }
};
