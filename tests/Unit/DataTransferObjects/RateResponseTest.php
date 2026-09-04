<?php

use App\DataTransferObjects\Shipping\RateResponse;

it('round-trips the offer identifier through Livewire serialization', function (): void {
    $rate = new RateResponse(
        carrier: 'OnTrac',
        serviceCode: 'ONTRAC_MFN_GROUND',
        serviceName: 'OnTrac Ground',
        price: 5.79,
        offerId: '01K4XJ5S8ZQ7V6R3N2M1P0T9AB',
    );

    expect(RateResponse::fromArray($rate->toArray())->offerId)->toBe('01K4XJ5S8ZQ7V6R3N2M1P0T9AB');
});

it('carries nothing that could buy a label on its own', function (): void {
    $rate = new RateResponse(
        carrier: 'OnTrac',
        serviceCode: 'ONTRAC_MFN_GROUND',
        serviceName: 'OnTrac Ground',
        price: 5.79,
        offerId: '01K4XJ5S8ZQ7V6R3N2M1P0T9AB',
    );

    // ADR-0002 decision 4: the browser holds the opaque identifier, and the
    // tokens, source instance, environment and expiry stay server-side. This
    // array is browser state.
    expect(array_keys($rate->toArray()))->toBe([
        'carrier',
        'serviceCode',
        'serviceName',
        'price',
        'deliveryCommitment',
        'deliveryDate',
        'transitTime',
        'metadata',
        'priceUnknown',
        'offerId',
    ]);
});

it('defaults to no offer, for rates from sources that issue none', function (): void {
    $rate = new RateResponse('USPS', 'USPS_GROUND_ADVANTAGE', 'Ground Advantage', 6.93);

    expect($rate->offerId)->toBeNull()
        ->and(RateResponse::fromArray([
            'carrier' => 'USPS',
            'serviceCode' => 'USPS_GROUND_ADVANTAGE',
            'serviceName' => 'Ground Advantage',
            'price' => 6.93,
            'deliveryCommitment' => null,
            'deliveryDate' => null,
            'transitTime' => null,
        ])->offerId)->toBeNull();
});
