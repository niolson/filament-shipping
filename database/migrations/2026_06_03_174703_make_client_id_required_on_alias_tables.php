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
            // MySQL requires dropping the SET NULL FK before the column can become NOT NULL
            Schema::table('shipping_method_aliases', fn (Blueprint $table) => $table->dropForeign(['client_id']));
            Schema::table('channel_aliases', fn (Blueprint $table) => $table->dropForeign(['client_id']));
        }

        // Assign any null rows to the default client before tightening the column
        DB::statement('UPDATE shipping_method_aliases SET client_id = (SELECT id FROM clients WHERE is_default = 1 LIMIT 1) WHERE client_id IS NULL');
        DB::statement('UPDATE channel_aliases SET client_id = (SELECT id FROM clients WHERE is_default = 1 LIMIT 1) WHERE client_id IS NULL');

        Schema::table('shipping_method_aliases', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable(false)->change());
        Schema::table('channel_aliases', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable(false)->change());

        if ($isMySQL) {
            Schema::table('shipping_method_aliases', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete());
            Schema::table('channel_aliases', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        $isMySQL = Schema::getConnection()->getDriverName() === 'mysql';

        if ($isMySQL) {
            Schema::table('shipping_method_aliases', fn (Blueprint $table) => $table->dropForeign(['client_id']));
            Schema::table('channel_aliases', fn (Blueprint $table) => $table->dropForeign(['client_id']));
        }

        Schema::table('shipping_method_aliases', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable()->change());
        Schema::table('channel_aliases', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable()->change());

        if ($isMySQL) {
            Schema::table('shipping_method_aliases', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete());
            Schema::table('channel_aliases', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete());
        }
    }
};
