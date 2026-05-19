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
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index('client_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index(['client_id', 'sku']);
        });

        Schema::table('import_sources', function (Blueprint $table) {
            $table->dropUnique('import_sources_config_key_unique');
            $table->foreignId('client_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->unique(['client_id', 'config_key'], 'import_sources_client_config_unique');
        });

        Schema::table('shipping_method_aliases', function (Blueprint $table) {
            $table->dropUnique('shipping_method_aliases_reference_unique');
            $table->foreignId('client_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->unique(['client_id', 'reference'], 'shipping_method_aliases_client_reference_unique');
        });

        Schema::table('channel_aliases', function (Blueprint $table) {
            $table->dropUnique('channel_aliases_reference_unique');
            $table->foreignId('client_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->unique(['client_id', 'reference'], 'channel_aliases_client_reference_unique');
        });

        Schema::table('shipping_rules', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index(['client_id', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipping_rules', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropIndex(['client_id', 'priority']);
            $table->dropColumn('client_id');
        });

        Schema::table('channel_aliases', function (Blueprint $table) {
            $table->dropUnique('channel_aliases_client_reference_unique');
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');
            $table->unique('reference');
        });

        Schema::table('shipping_method_aliases', function (Blueprint $table) {
            $table->dropUnique('shipping_method_aliases_client_reference_unique');
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');
            $table->unique('reference');
        });

        Schema::table('import_sources', function (Blueprint $table) {
            $table->dropUnique('import_sources_client_config_unique');
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');
            $table->unique('config_key');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropIndex(['client_id', 'sku']);
            $table->dropColumn('client_id');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropIndex(['client_id']);
            $table->dropColumn('client_id');
        });
    }
};
