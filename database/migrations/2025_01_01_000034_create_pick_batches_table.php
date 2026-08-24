<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pick_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('status')->default('in_progress');
            $table->unsignedInteger('total_shipments')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('summary_printed_at')->nullable();
            $table->timestamps();
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
