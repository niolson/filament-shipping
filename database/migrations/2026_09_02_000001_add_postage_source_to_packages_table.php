<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record where a package's postage was bought, separately from the carrier
     * of record it has always shared a column with. See ADR-0002.
     *
     * Both columns land nullable and no existing row is touched. A backfill,
     * not a column default, is what fills them in — a default would claim a
     * provenance for rows nobody has looked at yet.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Named for what it records — where the postage was bought — which
            // is not necessarily the shipment's import source.
            //
            // nullOnDelete matches carrier_account_id: deleting a data source
            // costs us the pointer on labels bought through it, the same way
            // deleting a carrier account already does.
            $table->foreignId('postage_data_source_id')
                ->nullable()
                ->after('carrier_account_id')
                ->constrained('data_sources')
                ->nullOnDelete();

            $table->string('postage_source', 32)
                ->nullable()
                ->after('postage_data_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropForeign(['postage_data_source_id']);
            $table->dropColumn(['postage_data_source_id', 'postage_source']);
        });
    }
};
