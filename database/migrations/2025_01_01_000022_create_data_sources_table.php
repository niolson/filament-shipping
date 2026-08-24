<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('name');
            $table->string('source_type');
            $table->boolean('active')->default(true);
            $table->boolean('global_export')->default(false);
            $table->string('schedule_interval')->nullable();
            $table->json('settings')->nullable();
            $table->longText('secret_settings')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};
