<?php

namespace Database\Seeders;

use App\Models\Carrier;
use App\Services\Carriers\ShopifyAdapter;
use Illuminate\Database\Seeder;

class CarrierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // The cutoff hangs off the carrier row rather than a name-keyed table in
        // code, so renaming a carrier cannot silently drop its pickup policy.
        $usps = Carrier::firstOrCreate(['name' => 'USPS'], ['pickup_cutoff_hour' => 20]);
        foreach ([
            ['name' => 'Ground Advantage', 'service_code' => 'USPS_GROUND_ADVANTAGE'],
            ['name' => 'Priority Mail', 'service_code' => 'PRIORITY_MAIL'],
            ['name' => 'Priority Mail Express', 'service_code' => 'PRIORITY_MAIL_EXPRESS'],
            ['name' => 'Priority Mail International', 'service_code' => 'PRIORITY_MAIL_INTERNATIONAL'],
        ] as $service) {
            // Every USPS service is USPS -- there's no scenario in this app
            // where a USPS service should be blocked from a PO Box or a
            // military address.
            $usps->carrierServices()->firstOrCreate(
                ['service_code' => $service['service_code']],
                [
                    'name' => $service['name'],
                    'can_ship_to_po_boxes' => true,
                    'can_ship_to_military_addresses' => true,
                ],
            );
        }

        $fedex = Carrier::firstOrCreate(['name' => 'FedEx']);
        foreach ([
            ['name' => 'FedEx Home Delivery®', 'service_code' => 'GROUND_HOME_DELIVERY'],
            ['name' => 'FedEx Ground®', 'service_code' => 'FEDEX_GROUND'],
            ['name' => 'FedEx Ground® Economy', 'service_code' => 'SMART_POST'],
            ['name' => 'FedEx International Priority®', 'service_code' => 'FEDEX_INTERNATIONAL_PRIORITY'],
            ['name' => 'FedEx International Priority® Express', 'service_code' => 'FEDEX_INTERNATIONAL_PRIORITY_EXPRESS'],
            ['name' => 'FedEx International First®', 'service_code' => 'INTERNATIONAL_FIRST'],
            ['name' => 'FedEx International Economy®', 'service_code' => 'FEDEX_INTERNATIONAL_ECONOMY'],
            ['name' => 'FedEx International Connect Plus®', 'service_code' => 'FEDEX_INTERNATIONAL_CONNECT_PLUS'],
            ['name' => 'FedEx First Overnight®', 'service_code' => 'FIRST_OVERNIGHT'],
            ['name' => 'FedEx Priority Overnight®', 'service_code' => 'PRIORITY_OVERNIGHT'],
            ['name' => 'FedEx Standard Overnight®', 'service_code' => 'STANDARD_OVERNIGHT'],
            ['name' => 'FedEx 2Day®', 'service_code' => 'FEDEX_2_DAY'],
            ['name' => 'FedEx 2Day® A.M.', 'service_code' => 'FEDEX_2_DAY_AM'],
            ['name' => 'FedEx Express Saver®', 'service_code' => 'FEDEX_EXPRESS_SAVER'],
        ] as $service) {
            // FedEx Ground Economy (service code SMART_POST, the FedEx API's
            // longstanding name for it) is FedEx's only USPS-last-mile
            // service -- plain FedEx Ground/Home Delivery/Express etc. cannot
            // reach a PO Box or military address and correctly stay false.
            $isGroundEconomy = $service['service_code'] === 'SMART_POST';

            $fedex->carrierServices()->firstOrCreate(
                ['service_code' => $service['service_code']],
                [
                    'name' => $service['name'],
                    'can_ship_to_po_boxes' => $isGroundEconomy,
                    'can_ship_to_military_addresses' => $isGroundEconomy,
                ],
            );
        }

        $ups = Carrier::firstOrCreate(['name' => 'UPS']);
        foreach ([
            ['name' => 'UPS Ground', 'service_code' => '03'],
            ['name' => 'UPS 3 Day Select', 'service_code' => '12'],
            ['name' => 'UPS 2nd Day Air', 'service_code' => '02'],
            ['name' => 'UPS 2nd Day Air A.M.', 'service_code' => '59'],
            ['name' => 'UPS Next Day Air Saver', 'service_code' => '13'],
            ['name' => 'UPS Next Day Air', 'service_code' => '01'],
            ['name' => 'UPS Next Day Air Early', 'service_code' => '14'],
            ['name' => 'UPS Worldwide Express', 'service_code' => '07'],
            ['name' => 'UPS Worldwide Expedited', 'service_code' => '08'],
            ['name' => 'UPS Worldwide Saver', 'service_code' => '65'],
            ['name' => 'UPS Standard', 'service_code' => '11'],
            ['name' => 'UPS Ground Saver', 'service_code' => '92'],
            ['name' => 'UPS Ground Saver', 'service_code' => '93'],
        ] as $service) {
            // UPS Ground Saver (what UPS's API still calls SurePost) is UPS's
            // only USPS-last-mile service -- like FedEx Ground Economy above,
            // it can reach a PO Box or military address; plain UPS Ground/Air
            // services cannot. UPS splits it into two codes by weight -- 92
            // under 1lb, 93 at 1lb or greater -- and its Shop-rating response
            // returns whichever applies to the request, so both must be
            // seeded or one weight tier's rates get silently filtered out.
            $isGroundSaver = in_array($service['service_code'], ['92', '93'], true);

            $ups->carrierServices()->firstOrCreate(
                ['service_code' => $service['service_code']],
                [
                    'name' => $service['name'],
                    'can_ship_to_po_boxes' => $isGroundSaver,
                    'can_ship_to_military_addresses' => $isGroundSaver,
                ],
            );
        }

        // Shopify Shipping buys postage on the merchant's Shopify account
        // instead of a carrier account of ours, which is how a shop without an
        // NSA reaches USPS Connect eCommerce rates. Its service codes are the
        // `carrier:service` pairs Shopify's preferredRateSelection takes, or
        // `auto` to let Shopify choose the rate the way its admin would.
        //
        // Only `auto` is seeded: Shopify publishes no list of service codes and
        // has no API to enumerate them, so every explicit pair has to be
        // confirmed against a real purchase before it is worth cataloguing.
        // Add confirmed ones under Carrier Services.
        $shopify = Carrier::firstOrCreate(['name' => ShopifyAdapter::CARRIER_NAME]);
        $shopify->carrierServices()->firstOrCreate(
            ['service_code' => ShopifyAdapter::AUTO_SERVICE_CODE],
            [
                'name' => "Shopify's choice",
                // Shopify picks the carrier itself, and only USPS reaches a PO
                // Box or an APO/FPO, so its choice for those destinations is
                // constrained the same way ours would be.
                'can_ship_to_po_boxes' => true,
                'can_ship_to_military_addresses' => true,
            ],
        );
    }
}
