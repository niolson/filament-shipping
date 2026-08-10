<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('package_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'data_source_id']);
            $table->index(['package_id', 'status', 'locked_at']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->index(['status', 'exported', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['status', 'exported', 'id']);
        });

        Schema::dropIfExists('package_exports');
    }
};
