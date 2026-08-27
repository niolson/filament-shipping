<?php

use PHPUnit\Framework\AssertionFailedError;

/**
 * Guards assertMatchesSpApiSchema() itself.
 *
 * A schema assertion that silently passes everything is worse than none at all:
 * it reads as coverage while catching nothing. If the vendored file moves, or
 * the "$ref" into "#/definitions" stops resolving, these tests fail rather than
 * the Amazon export tests quietly going green.
 */
function validConfirmShipmentBody(array $overrides = []): array
{
    return array_replace_recursive([
        'marketplaceId' => 'ATVPDKIKX0DER',
        'packageDetail' => [
            'packageReferenceId' => '1',
            'carrierCode' => 'USPS',
            'shippingMethod' => 'Priority Mail',
            'trackingNumber' => 'TRACK123',
            'shipDate' => '2026-08-07T15:30:00+00:00',
            'orderItems' => [[
                'orderItemId' => 'AMAZON-ITEM-123',
                'quantity' => 2,
                'transparencyCodes' => ['AZ:TRANSPARENCY'],
            ]],
        ],
    ], $overrides);
}

it('accepts a well-formed confirm shipment body', function (): void {
    assertMatchesSpApiSchema(validConfirmShipmentBody(), 'ConfirmShipmentRequest');
});

it('accepts a confirm shipment body without the optional fields', function (): void {
    $body = validConfirmShipmentBody();
    unset($body['packageDetail']['shippingMethod']);
    unset($body['packageDetail']['orderItems'][0]['transparencyCodes']);

    assertMatchesSpApiSchema($body, 'ConfirmShipmentRequest');
});

it('rejects a confirm shipment body missing a required top-level property', function (): void {
    $body = validConfirmShipmentBody();
    unset($body['marketplaceId']);

    expect(fn () => assertMatchesSpApiSchema($body, 'ConfirmShipmentRequest'))
        ->toThrow(AssertionFailedError::class, 'marketplaceId');
});

it('rejects a confirm shipment body missing a required package property', function (string $property): void {
    $body = validConfirmShipmentBody();
    unset($body['packageDetail'][$property]);

    expect(fn () => assertMatchesSpApiSchema($body, 'ConfirmShipmentRequest'))
        ->toThrow(AssertionFailedError::class, $property);
})->with(['packageReferenceId', 'carrierCode', 'trackingNumber', 'shipDate', 'orderItems']);

it('rejects a non-integer order item quantity', function (): void {
    $body = validConfirmShipmentBody();
    $body['packageDetail']['orderItems'][0]['quantity'] = '2';

    expect(fn () => assertMatchesSpApiSchema($body, 'ConfirmShipmentRequest'))
        ->toThrow(AssertionFailedError::class);
});

it('rejects a ship date that is not ISO 8601', function (): void {
    $body = validConfirmShipmentBody(['packageDetail' => ['shipDate' => '2026-08-07 15:30:00']]);

    expect(fn () => assertMatchesSpApiSchema($body, 'ConfirmShipmentRequest'))
        ->toThrow(AssertionFailedError::class);
});

it('rejects an unknown codCollectionMethod', function (): void {
    $body = validConfirmShipmentBody(['codCollectionMethod' => 'Cash']);

    expect(fn () => assertMatchesSpApiSchema($body, 'ConfirmShipmentRequest'))
        ->toThrow(AssertionFailedError::class);
});

it('fails loudly when the schema document is missing', function (): void {
    expect(fn () => assertMatchesSpApiSchema(validConfirmShipmentBody(), 'ConfirmShipmentRequest', 'doesNotExist'))
        ->toThrow(AssertionFailedError::class, 'missing');
});
