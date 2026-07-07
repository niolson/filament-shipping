<?php

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\SpecialService;
use Illuminate\Database\Seeder;

class CarrierServiceSpecialServiceSeeder extends Seeder
{
    /**
     * Seed carrier-service-level special service scoping (which specific
     * carrier services can carry which special services).
     *
     * Scoping is opt-in per carrier: a special service with no rows for any of
     * a carrier's services is unrestricted for that carrier. USPS deliberately
     * has no Saturday rows — it delivers Saturday as standard service.
     *
     * The Saturday lists mirror the adapters' operational day maps
     * (FedexAdapter/UpsAdapter SATURDAY_DELIVERY_DAY_MAP).
     */
    public function run(): void
    {
        $saturdayByCarrier = [
            'FedEx' => [
                'FIRST_OVERNIGHT',
                'PRIORITY_OVERNIGHT',
                'STANDARD_OVERNIGHT',
                'FEDEX_2_DAY_AM',
                'FEDEX_2_DAY',
                'EXPRESS_SAVER',
                'FEDEX_EXPRESS_SAVER',
            ],
            'UPS' => [
                '14', // Next Day Air Early
                '01', // Next Day Air
                '13', // Next Day Air Saver
                '02', // 2nd Day Air
                '12', // 3 Day Select
            ],
        ];

        $saturday = SpecialService::where('code', 'saturday_delivery')->first();

        if (! $saturday) {
            return;
        }

        foreach ($saturdayByCarrier as $carrierName => $serviceCodes) {
            $carrier = Carrier::where('name', $carrierName)->first();

            if (! $carrier) {
                continue;
            }

            $carrierServiceIds = $carrier->carrierServices()
                ->whereIn('service_code', $serviceCodes)
                ->pluck('id');

            foreach ($carrierServiceIds as $carrierServiceId) {
                $saturday->carrierServices()->syncWithoutDetaching([
                    $carrierServiceId => ['restricted_countries' => null],
                ]);
            }
        }
    }
}
