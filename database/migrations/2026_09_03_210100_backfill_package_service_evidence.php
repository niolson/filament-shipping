<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // Shopify never confirmed a service: it reports none before or after
            // the buy, so a service value on one of its packages is the
            // preference we asked for — audit metadata sitting in the wrong
            // column. Move it rather than certifying it, which would publish a
            // guess to a marketplace. ADR-0003 decision 5.
            //
            // Scoped to Shopify by source type rather than to sales-channel
            // postage in general: Amazon Buy Shipping is also bought through a
            // data source and *does* report the service it sold, so sweeping
            // every `postage_data_source` row would demote a genuine
            // confirmation. No such row can exist when this runs — Buy Shipping
            // postdates this migration — but the predicate should say what it
            // means rather than rely on that.
            DB::table('packages')
                ->where('postage_source', 'postage_data_source')
                ->where('service_evidence', 'unknown')
                ->whereNotNull('service')
                ->whereIn('postage_data_source_id', function ($query): void {
                    $query->select('id')
                        ->from('data_sources')
                        ->where('source_type', 'App\\Services\\ShipmentImport\\Sources\\ShopifySource');
                })
                ->update([
                    'requested_service' => DB::raw('service'),
                    'service' => null,
                ]);

            // Everything left holding a service had it reported by whoever sold
            // the postage. Rows with no service stay `unknown`, which is what
            // the column already defaults to.
            DB::table('packages')
                ->where('service_evidence', 'unknown')
                ->whereNotNull('service')
                ->where('service', '!=', '')
                ->update(['service_evidence' => 'confirmed']);
        });
    }

    public function down(): void
    {
        // Which services were confirmed cannot be distinguished afterwards from
        // data the application recorded itself.
    }
};
