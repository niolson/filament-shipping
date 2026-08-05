<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * can_ship_to_po_boxes / can_ship_to_military_addresses default to false,
     * which is correct for nearly every carrier service -- but it also means
     * USPS and FedEx Ground Economy (the actual USPS-last-mile services this
     * feature exists for) start out unable to rate anything to a PO Box or
     * military address until someone flips them on by hand. Backfill them.
     */
    public function up(): void
    {
        $uspsCarrierId = DB::table('carriers')->where('name', 'USPS')->value('id');

        if ($uspsCarrierId !== null) {
            // Every USPS service is USPS -- there's no scenario in this app
            // where a USPS service should be blocked from a PO Box or a
            // military address.
            DB::table('carrier_services')
                ->where('carrier_id', $uspsCarrierId)
                ->update([
                    'can_ship_to_po_boxes' => true,
                    'can_ship_to_military_addresses' => true,
                ]);
        }

        $fedexCarrierId = DB::table('carriers')->where('name', 'FedEx')->value('id');

        if ($fedexCarrierId !== null) {
            // FedEx Ground Economy (service code SMART_POST, the FedEx API's
            // longstanding name for it) is FedEx's only USPS-last-mile
            // service -- plain FedEx Ground/Home Delivery/Express etc. cannot
            // reach a PO Box or military address and correctly stay false.
            DB::table('carrier_services')
                ->where('carrier_id', $fedexCarrierId)
                ->where('service_code', 'SMART_POST')
                ->update([
                    'can_ship_to_po_boxes' => true,
                    'can_ship_to_military_addresses' => true,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $uspsCarrierId = DB::table('carriers')->where('name', 'USPS')->value('id');

        if ($uspsCarrierId !== null) {
            DB::table('carrier_services')
                ->where('carrier_id', $uspsCarrierId)
                ->update([
                    'can_ship_to_po_boxes' => false,
                    'can_ship_to_military_addresses' => false,
                ]);
        }

        $fedexCarrierId = DB::table('carriers')->where('name', 'FedEx')->value('id');

        if ($fedexCarrierId !== null) {
            DB::table('carrier_services')
                ->where('carrier_id', $fedexCarrierId)
                ->where('service_code', 'SMART_POST')
                ->update([
                    'can_ship_to_po_boxes' => false,
                    'can_ship_to_military_addresses' => false,
                ]);
        }
    }
};
