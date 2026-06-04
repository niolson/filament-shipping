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
        Schema::table('clients', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('logo');
            $table->text('custom_message')->nullable()->after('company_name');
            $table->text('return_instructions')->nullable()->after('custom_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'custom_message', 'return_instructions']);
        });
    }
};
