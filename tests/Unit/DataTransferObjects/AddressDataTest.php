<?php

use App\DataTransferObjects\Shipping\AddressData;

function makeAddress(string $streetAddress, ?string $streetAddress2 = null, ?string $uspsCarrierRoute = null, string $country = 'US'): AddressData
{
    return new AddressData(
        firstName: 'Jane',
        lastName: 'Doe',
        streetAddress: $streetAddress,
        city: 'Springfield',
        stateOrProvince: 'IL',
        postalCode: '62704',
        country: $country,
        streetAddress2: $streetAddress2,
        uspsCarrierRoute: $uspsCarrierRoute,
    );
}

it('detects standard PO Box formats', function (string $line): void {
    expect(makeAddress($line)->isPoBox())->toBeTrue();
})->with([
    'PO Box 411',
    'P.O. Box 1142',
    'Pobox 1982',
    'PO Box 26384',
    'POB 711',
    '6873 N Ridge Rd Box186',
]);

it('detects rural route, highway contract, and general delivery box formats', function (string $line): void {
    expect(makeAddress($line)->isPoBox())->toBeTrue();
})->with([
    'RR 1 Box 42108',
    'HC 71 Box 21',
    'Star Route Box 12',
    'GENERAL DELIVERY',
]);

it('detects letter-prefixed box numbers and "Box No." phrasing', function (): void {
    expect(makeAddress('Rt 1 Box A11')->isPoBox())->toBeTrue()
        ->and(makeAddress('263 Alden St', 'Box No. 2544')->isPoBox())->toBeTrue()
        ->and(makeAddress('PO BOX B')->isPoBox())->toBeTrue();
});

it('does not flag street names that merely contain "box"', function (string $line): void {
    expect(makeAddress($line)->isPoBox())->toBeFalse();
})->with([
    '535 Boxwood Dr',
    '762 Box Canyon Ct',
    '7205 Box Car Ct',
    '5774 Box Elder Rd',
    '210 Box Ln',
]);

it('does not flag property-access lockbox delivery notes on real street addresses', function (): void {
    $address = makeAddress('118 Ponderosa Court', 'CAN USE LOCK BOX NUMBER 0529#');

    expect($address->isPoBox())->toBeFalse();
});

it('checks the second address line too', function (): void {
    $address = makeAddress('123 Warehouse Way', 'PO Box 42');

    expect($address->isPoBox())->toBeTrue();
});

it('is never a PO Box outside the US', function (): void {
    $address = makeAddress('PO Box 42', country: 'CA');

    expect($address->isPoBox())->toBeFalse();
});

it('prefers the USPS carrier route over the regex when available', function (): void {
    // Carrier route "B..." is a dedicated PO Box route -- trusted even though
    // the street text alone wouldn't match the regex.
    $poBoxRoute = makeAddress('123 Warehouse Way', uspsCarrierRoute: 'B012');
    expect($poBoxRoute->isPoBox())->toBeTrue();

    // A city route means USPS confirmed this is a real street address, so it
    // overrides a regex-only false positive.
    $cityRoute = makeAddress('PO Box 42', uspsCarrierRoute: 'C018');
    expect($cityRoute->isPoBox())->toBeFalse();
});

it('does not let a PO Box also read as a military address', function (): void {
    $address = makeAddress('PO Box 42');

    expect($address->isPoBox())->toBeTrue()
        ->and($address->isMilitary())->toBeFalse();
});
