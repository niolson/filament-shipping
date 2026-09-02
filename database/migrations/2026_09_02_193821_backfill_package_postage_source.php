<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $unsafePackage = DB::table('packages')
                ->whereNull('postage_source')
                ->where('status', 'shipped')
                ->where(function ($query): void {
                    $query->where('carrier', 'Shopify')
                        ->orWhere('metadata', 'like', '%shopify_shipping_label_id%')
                        ->orWhereNotNull('postage_data_source_id');
                })
                ->orderBy('id')
                ->first(['id']);

            if ($unsafePackage !== null) {
                throw new RuntimeException(
                    "Package {$unsafePackage->id} may contain sales-channel postage and cannot be safely backfilled."
                );
            }

            DB::table('packages')
                ->whereNull('postage_source')
                ->where('status', 'shipped')
                ->update(['postage_source' => 'carrier_account']);
        });
    }

    public function down(): void
    {
        // This provenance cannot be safely distinguished from data recorded by the application.
    }
};
