<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isMySQL = Schema::getConnection()->getDriverName() === 'mysql';

        if ($isMySQL) {
            Schema::table('products', fn (Blueprint $table) => $table->dropForeign(['client_id']));
            Schema::table('shipments', fn (Blueprint $table) => $table->dropForeign(['client_id']));
        }

        DB::statement('UPDATE products SET client_id = (SELECT id FROM clients WHERE is_default = 1 LIMIT 1) WHERE client_id IS NULL');
        DB::statement('UPDATE shipments SET client_id = (SELECT id FROM clients WHERE is_default = 1 LIMIT 1) WHERE client_id IS NULL');

        Schema::table('products', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable(false)->change());
        Schema::table('shipments', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable(false)->change());

        if ($isMySQL) {
            Schema::table('products', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete());
            Schema::table('shipments', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        $isMySQL = Schema::getConnection()->getDriverName() === 'mysql';

        if ($isMySQL) {
            Schema::table('products', fn (Blueprint $table) => $table->dropForeign(['client_id']));
            Schema::table('shipments', fn (Blueprint $table) => $table->dropForeign(['client_id']));
        }

        Schema::table('products', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable()->change());
        Schema::table('shipments', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable()->change());

        if ($isMySQL) {
            Schema::table('products', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete());
            Schema::table('shipments', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete());
        }
    }
};
