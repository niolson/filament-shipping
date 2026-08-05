<?php

use App\Models\Carrier;
use App\Models\Setting;
use Database\Seeders\DatabaseSeeder;

it('marks setup as complete when the database is seeded', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(Setting::find('setup_complete')?->value)->toBeTrue()
        ->and(Setting::find('setup_wizard_step')?->value)->toBe(1);
});

it('includes tenant reference data when the database is seeded', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(Carrier::where('name', 'USPS')->exists())->toBeTrue()
        ->and(Carrier::where('name', 'FedEx')->exists())->toBeTrue()
        ->and(Carrier::where('name', 'UPS')->exists())->toBeTrue();
});

it('seeds fedex carrier services with trademarked display names', function (): void {
    $this->seed(DatabaseSeeder::class);

    $fedex = Carrier::query()
        ->where('name', 'FedEx')
        ->firstOrFail();

    expect($fedex->carrierServices()->where('service_code', 'GROUND_HOME_DELIVERY')->value('name'))->toBe('FedEx Home Delivery®')
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_GROUND')->value('name'))->toBe('FedEx Ground®')
        ->and($fedex->carrierServices()->where('service_code', 'SMART_POST')->value('name'))->toBe('FedEx Ground® Economy')
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_INTERNATIONAL_PRIORITY')->value('name'))->toBe('FedEx International Priority®')
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_INTERNATIONAL_ECONOMY')->value('name'))->toBe('FedEx International Economy®')
        ->and($fedex->carrierServices()->where('service_code', 'PRIORITY_OVERNIGHT')->value('name'))->toBe('FedEx Priority Overnight®')
        ->and($fedex->carrierServices()->where('service_code', 'STANDARD_OVERNIGHT')->value('name'))->toBe('FedEx Standard Overnight®')
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_2_DAY')->value('name'))->toBe('FedEx 2Day®')
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_2_DAY_AM')->value('name'))->toBe('FedEx 2Day® A.M.')
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_EXPRESS_SAVER')->value('name'))->toBe('FedEx Express Saver®');
});

it('marks every USPS service and only FedEx Ground Economy as PO Box / military capable', function (): void {
    $this->seed(DatabaseSeeder::class);

    $usps = Carrier::query()->where('name', 'USPS')->firstOrFail();

    expect($usps->carrierServices()->where('can_ship_to_po_boxes', true)->count())
        ->toBe($usps->carrierServices()->count())
        ->and($usps->carrierServices()->where('can_ship_to_military_addresses', true)->count())
        ->toBe($usps->carrierServices()->count());

    $fedex = Carrier::query()->where('name', 'FedEx')->firstOrFail();

    expect($fedex->carrierServices()->where('service_code', 'SMART_POST')->value('can_ship_to_po_boxes'))->toBeTrue()
        ->and($fedex->carrierServices()->where('service_code', 'SMART_POST')->value('can_ship_to_military_addresses'))->toBeTrue()
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_GROUND')->value('can_ship_to_po_boxes'))->toBeFalse()
        ->and($fedex->carrierServices()->where('service_code', 'FEDEX_GROUND')->value('can_ship_to_military_addresses'))->toBeFalse();
});
