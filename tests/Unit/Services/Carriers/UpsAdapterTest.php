<?php

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\TrackingStatus;
use App\Exceptions\Carriers\CarrierRateFetchException;
use App\Http\Integrations\Ups\Requests\CreateShipment;
use App\Http\Integrations\Ups\Requests\Rate;
use App\Http\Integrations\Ups\Requests\TrackShipment;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CarrierAccountScope;
use App\Models\Client;
use App\Models\Package;
use App\Services\Carriers\UpsAdapter;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->adapter = new UpsAdapter;
    createUpsAccount();
});

it('returns false when only an empty active account exists', function (): void {
    $carrierId = CarrierAccount::query()->firstOrFail()->carrier_id;
    CarrierAccount::query()->delete();
    CarrierAccount::factory()->create([
        'carrier_id' => $carrierId,
        'credentials' => null,
        'secret_credentials' => null,
    ]);

    expect($this->adapter->isConfigured())->toBeFalse();
});

it('supports tracking', function (): void {
    expect($this->adapter->supportsTracking())->toBeTrue();
});

it('prepares UPS rates without keeping account state on the adapter', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => []]]),
    ]);

    CarrierAccount::query()->delete();

    $carrier = Carrier::firstOrCreate(['name' => 'UPS']);
    $firstClient = Client::factory()->create();
    $secondClient = Client::factory()->create();

    $firstAccount = CarrierAccount::factory()->create([
        'carrier_id' => $carrier->id,
        'credentials' => ['account_number' => 'first_account'],
        'secret_credentials' => ['client_id' => 'first_key', 'client_secret' => 'first_secret'],
    ]);
    $secondAccount = CarrierAccount::factory()->create([
        'carrier_id' => $carrier->id,
        'credentials' => ['account_number' => 'second_account'],
        'secret_credentials' => ['client_id' => 'second_key', 'client_secret' => 'second_secret'],
    ]);

    CarrierAccountScope::factory()->forAccount($firstAccount)->clientScoped($firstClient)->create();
    CarrierAccountScope::factory()->forAccount($secondAccount)->clientScoped($secondClient)->create();

    $this->adapter->getRates(rateRequestForClient($firstClient->id), ['03']);
    $this->adapter->getRates(rateRequestForClient($secondClient->id), ['03']);

    expect((new ReflectionClass($this->adapter))->hasProperty('currentAccount'))->toBeFalse();
});

it('throws CarrierRateFetchException when the UPS rate API fails', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['errors' => [['message' => 'Internal Server Error']]], 500),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
    );

    expect(fn () => $this->adapter->getRates($request, ['03']))
        ->toThrow(CarrierRateFetchException::class, 'Failed to fetch rates from UPS');
});

it('wraps the original exception as previous when rate fetch fails', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['errors' => [['message' => 'Service Unavailable']]], 503),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 5.0, length: 12, width: 10, height: 8)],
    );

    try {
        $this->adapter->getRates($request, ['03']);
        $this->fail('Expected CarrierRateFetchException was not thrown');
    } catch (CarrierRateFetchException $e) {
        expect($e->carrier)->toBe('UPS')
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);
    }
});

it('maps a UPS tracking response into normalized tracking data', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'trackResponse' => [
                'shipment' => [
                    [
                        'package' => [
                            [
                                'trackingNumber' => '1Z999AA10123456784',
                                'currentStatus' => [
                                    'description' => 'On the Way',
                                    'simplifiedTextDescription' => 'In Transit',
                                    'statusCode' => '005',
                                    'type' => 'I',
                                ],
                                'deliveryDate' => [
                                    [
                                        'type' => 'SDD',
                                        'date' => '20260415',
                                    ],
                                ],
                                'deliveryTime' => [
                                    'type' => 'CMT',
                                    'endTime' => '200000',
                                ],
                                'activity' => [
                                    [
                                        'date' => '20260413',
                                        'time' => '091500',
                                        'gmtDate' => '20260413',
                                        'gmtTime' => '161500',
                                        'gmtOffset' => '-07:00',
                                        'location' => [
                                            'address' => [
                                                'city' => 'Seattle',
                                                'stateProvince' => 'WA',
                                                'countryCode' => 'US',
                                            ],
                                        ],
                                        'status' => [
                                            'description' => 'Departed from Facility',
                                            'simplifiedTextDescription' => 'In Transit',
                                            'statusCode' => 'DP',
                                            'type' => 'I',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456784',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(TrackingStatus::InTransit)
        ->and($response->statusLabel)->toBe('On the Way')
        ->and($response->estimatedDeliveryAt?->format('Y-m-d H:i:s'))->toBe('2026-04-15 20:00:00')
        ->and($response->events)->toHaveCount(1)
        ->and($response->events[0]->description)->toBe('Departed from Facility')
        ->and($response->events[0]->location)->toBe('Seattle, WA, US');
});

it('maps UPS delivered responses into delivered tracking status', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'trackResponse' => [
                'shipment' => [
                    [
                        'package' => [
                            [
                                'currentStatus' => [
                                    'description' => 'Delivered',
                                    'simplifiedTextDescription' => 'Delivered',
                                    'statusCode' => '003',
                                    'type' => 'D',
                                ],
                                'deliveryDate' => [
                                    [
                                        'type' => 'DEL',
                                        'date' => '20260414',
                                    ],
                                ],
                                'deliveryTime' => [
                                    'type' => 'DEL',
                                    'endTime' => '134500',
                                ],
                                'activity' => [
                                    [
                                        'date' => '20260414',
                                        'time' => '134500',
                                        'location' => [
                                            'address' => [
                                                'city' => 'Los Angeles',
                                                'stateProvince' => 'CA',
                                                'countryCode' => 'US',
                                            ],
                                        ],
                                        'status' => [
                                            'description' => 'Delivered',
                                            'simplifiedTextDescription' => 'Delivered',
                                            'statusCode' => 'DEL',
                                            'type' => 'D',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456784',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(TrackingStatus::Delivered)
        ->and($response->deliveredAt?->format('Y-m-d H:i:s'))->toBe('2026-04-14 13:45:00');
});

it('falls back to the UPS summary delivery date when no delivered scan event exists', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'trackResponse' => [
                'shipment' => [
                    [
                        'package' => [
                            [
                                'currentStatus' => [
                                    'description' => 'Delivered',
                                    'simplifiedTextDescription' => 'Delivered',
                                    'statusCode' => '003',
                                    'type' => 'D',
                                ],
                                'deliveryDate' => [
                                    ['type' => 'DEL', 'date' => '20260414'],
                                ],
                                'deliveryTime' => [
                                    'type' => 'DEL',
                                    'endTime' => '134500',
                                ],
                                // Only a non-delivered scan event: deliveredAt must
                                // come from the summary deliveryDate[DEL] fallback.
                                'activity' => [
                                    [
                                        'date' => '20260413',
                                        'time' => '090000',
                                        'status' => [
                                            'description' => 'Origin Scan',
                                            'statusCode' => 'OR',
                                            'type' => 'I',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456785',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->status)->toBe(TrackingStatus::Delivered)
        ->and($response->deliveredAt?->format('Y-m-d H:i:s'))->toBe('2026-04-14 13:45:00');
});

it('defers to the UPS summary delivery date when the delivered scan event has no timestamp', function (): void {
    // Aligns UPS with the bug #1 fix: a delivered scan event that carries no
    // parseable timestamp must not short-circuit deliveredAt to null — the
    // shared resolveDeliveredAt template falls through to the summary fallback.
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'trackResponse' => [
                'shipment' => [
                    [
                        'package' => [
                            [
                                'currentStatus' => [
                                    'description' => 'Delivered',
                                    'statusCode' => '003',
                                    'type' => 'D',
                                ],
                                'deliveryDate' => [
                                    ['type' => 'DEL', 'date' => '20260414'],
                                ],
                                'deliveryTime' => [
                                    'type' => 'DEL',
                                    'endTime' => '134500',
                                ],
                                // Delivered scan event, but no date/time -> null timestamp.
                                'activity' => [
                                    [
                                        'status' => [
                                            'description' => 'Delivered',
                                            'statusCode' => 'DEL',
                                            'type' => 'D',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456786',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->status)->toBe(TrackingStatus::Delivered)
        ->and($response->deliveredAt?->format('Y-m-d H:i:s'))->toBe('2026-04-14 13:45:00');
});

it('maps UPS exception responses into exception tracking status', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'trackResponse' => [
                'shipment' => [
                    [
                        'package' => [
                            [
                                'currentStatus' => [
                                    'description' => 'Held for Pickup',
                                    'simplifiedTextDescription' => 'Held for Pickup',
                                    'statusCode' => 'HLD',
                                    'type' => 'X',
                                ],
                                'activity' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456784',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeTrue()
        ->and($response->status)->toBe(TrackingStatus::Exception);
});

it('returns failure when UPS tracking API errors', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make([
            'response' => [
                'errors' => [
                    [
                        'message' => 'Tracking number not found',
                    ],
                ],
            ],
        ], 404),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456784',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeFalse()
        ->and($response->message)->toBe('Tracking number not found');
});

it('handles non-json UPS tracking errors without crashing', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        TrackShipment::class => MockResponse::make(
            body: '<html><body>Service unavailable</body></html>',
            status: 503,
            headers: ['Content-Type' => 'text/html']
        ),
    ]);

    $package = Package::factory()->shipped()->create([
        'carrier' => 'UPS',
        'tracking_number' => '1Z999AA10123456784',
    ]);

    $response = $this->adapter->trackShipment($package);

    expect($response->success)->toBeFalse()
        ->and($response->message)->toContain('Response')
        ->and(data_get($response->details, 'raw.body'))->toContain('Service unavailable');
});

function upsSpecialServiceShipRequest(array $codes, array $config = []): ShipRequest
{
    return new ShipRequest(
        fromAddress: new AddressData(
            firstName: 'Shipping',
            lastName: 'Center',
            streetAddress: '123 Warehouse St',
            city: 'Seattle',
            stateOrProvince: 'WA',
            postalCode: '98072',
        ),
        toAddress: new AddressData(
            firstName: 'John',
            lastName: 'Doe',
            streetAddress: '456 Main St',
            city: 'Los Angeles',
            stateOrProvince: 'CA',
            postalCode: '90210',
        ),
        packageData: new PackageData(weight: 2.0, length: 10, width: 8, height: 4),
        selectedRate: new RateResponse(
            carrier: 'UPS',
            serviceCode: '03',
            serviceName: 'UPS Ground',
            price: 11.00,
            metadata: ['serviceCode' => '03'],
        ),
        specialServiceCodes: $codes,
        specialServiceConfig: $config,
    );
}

function fakeUpsShipEndpoints(): void
{
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateShipment::class => MockResponse::make([
            'ShipmentResponse' => [
                'ShipmentResults' => [
                    'ShipmentIdentificationNumber' => '1Z9999999999999999',
                    'ShipmentCharges' => [
                        'TotalCharges' => ['MonetaryValue' => '11.00'],
                    ],
                    'PackageResults' => [
                        'TrackingNumber' => '1Z9999999999999999',
                        'ShippingLabel' => ['GraphicImage' => 'R0lGODlhAQABAAAAACw='],
                    ],
                ],
            ],
        ]),
    ]);
}

it('maps delivery confirmation and declared value into the ship request', function (): void {
    fakeUpsShipEndpoints();

    $response = $this->adapter->createShipment(upsSpecialServiceShipRequest(
        ['adult_signature_required', 'declared_value'],
        ['declared_value' => ['amount' => 1250.50, 'currency' => 'USD']],
    ));

    expect($response->success)->toBeTrue()
        ->and($response->appliedServices)->toBe(['adult_signature_required', 'declared_value']);

    Saloon::assertSent(function ($request) {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $options = $request->body()->all()['ShipmentRequest']['Shipment']['Package'][0]['PackageServiceOptions'] ?? [];

        // Package-level DCIS code set: 3 = adult signature
        return ($options['DeliveryConfirmation']['DCISType'] ?? null) === '3'
            && ($options['DeclaredValue']['CurrencyCode'] ?? null) === 'USD'
            && ($options['DeclaredValue']['MonetaryValue'] ?? null) === '1250.50';
    });
});

it('uses DCIS type 2 for standard signature and sends no options for unwired codes', function (): void {
    fakeUpsShipEndpoints();

    $response = $this->adapter->createShipment(upsSpecialServiceShipRequest(
        ['signature_required', 'lithium_battery_in_equipment'],
    ));

    // Section II in-equipment batteries ship with no UPS API declaration —
    // only the signature option appears in the payload
    expect($response->success)->toBeTrue()
        ->and($response->appliedServices)->toBe(['signature_required']);

    Saloon::assertSent(function ($request) {
        if (! $request instanceof CreateShipment) {
            return false;
        }

        $options = $request->body()->all()['ShipmentRequest']['Shipment']['Package'][0]['PackageServiceOptions'] ?? [];

        return ($options['DeliveryConfirmation']['DCISType'] ?? null) === '2'
            && ! array_key_exists('DeclaredValue', $options)
            && ! array_key_exists('HazMat', $options);
    });
});

it('includes package service options in the rating request', function (): void {
    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        Rate::class => MockResponse::make(['RateResponse' => ['RatedShipment' => []]]),
    ]);

    $request = new RateRequest(
        originPostalCode: '98072',
        destinationPostalCode: '90210',
        packages: [new PackageData(weight: 2.0, length: 10, width: 8, height: 4)],
        specialServiceCodes: ['signature_required', 'declared_value'],
        specialServiceConfig: ['declared_value' => ['amount' => 300.00, 'currency' => 'USD']],
    );

    $this->adapter->getRates($request, ['03']);

    Saloon::assertSent(function ($request) {
        if (! $request instanceof Rate) {
            return false;
        }

        $options = $request->body()->all()['RateRequest']['Shipment']['Package']['PackageServiceOptions'] ?? [];

        return ($options['DeliveryConfirmation']['DCISType'] ?? null) === '2'
            && ($options['DeclaredValue']['MonetaryValue'] ?? null) === '300.00';
    });
});
