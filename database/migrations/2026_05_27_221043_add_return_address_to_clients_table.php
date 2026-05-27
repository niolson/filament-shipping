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
            $table->string('return_company')->nullable()->after('code');
            $table->string('return_name')->nullable()->after('return_company');
            $table->string('return_address1')->nullable()->after('return_name');
            $table->string('return_address2')->nullable()->after('return_address1');
            $table->string('return_city')->nullable()->after('return_address2');
            $table->string('return_state_or_province')->nullable()->after('return_city');
            $table->string('return_postal_code')->nullable()->after('return_state_or_province');
            $table->string('return_country', 2)->nullable()->after('return_postal_code');
            $table->string('return_phone')->nullable()->after('return_country');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'return_company',
                'return_name',
                'return_address1',
                'return_address2',
                'return_city',
                'return_state_or_province',
                'return_postal_code',
                'return_country',
                'return_phone',
            ]);
        });
    }
};
