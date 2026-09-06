<?php

use App\Enums\ServiceEvidence;
use App\Models\Package;

const COVERAGE_IMPB_GROUND_ADVANTAGE = '9300199999999900000011';
const COVERAGE_IMPB_UNLISTED_STC = '9299999999999900000036';

it('reports coverage without writing anything by default', function (): void {
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => COVERAGE_IMPB_GROUND_ADVANTAGE,
        'service' => null,
        'service_evidence' => ServiceEvidence::Unknown,
    ]);

    $this->artisan('app:infer-package-services')
        ->expectsOutputToContain('usps-impb-stc')
        ->expectsOutputToContain('Coverage: 1 of 1')
        ->assertSuccessful();

    expect(Package::first()->service)->toBeNull();
});

it('writes what it inferred under --apply', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => COVERAGE_IMPB_GROUND_ADVANTAGE,
        'service' => null,
        'service_evidence' => ServiceEvidence::Unknown,
    ]);

    $this->artisan('app:infer-package-services --apply')->assertSuccessful();

    expect($package->refresh()->service)->toBe('USPS Ground Advantage')
        ->and($package->service_evidence)->toBe(ServiceEvidence::Inferred);
});

it('counts what each rung left unknown', function (): void {
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => COVERAGE_IMPB_UNLISTED_STC,
        'service' => null,
        'service_evidence' => ServiceEvidence::Unknown,
        'label_data' => null,
    ]);

    $this->artisan('app:infer-package-services')
        ->expectsOutputToContain('service type code names no product')
        ->expectsOutputToContain('Coverage: 0 of 1')
        ->assertSuccessful();
});

it('leaves a confirmed service out of the measurement entirely', function (): void {
    Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => COVERAGE_IMPB_GROUND_ADVANTAGE,
        'service' => 'Priority Mail',
        'service_evidence' => ServiceEvidence::Confirmed,
    ]);

    $this->artisan('app:infer-package-services')
        ->expectsOutputToContain('No packages with an unconfirmed service')
        ->assertSuccessful();
});
