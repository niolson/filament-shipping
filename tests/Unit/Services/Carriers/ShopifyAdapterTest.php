<?php

use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\PostageSource;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Models\Carrier;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\Carriers\ShopifyAdapter;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->adapter = new ShopifyAdapter;
});

it('is configured only while an active Shopify data source exists', function (): void {
    expect($this->adapter->isConfigured())->toBeFalse();

    $source = createShopifyDataSource();

    expect($this->adapter->isConfigured())->toBeTrue();

    $source->update(['active' => false]);

    expect($this->adapter->isConfigured())->toBeFalse();
});

it('advertises catalogued services without a price for a Shopify-sourced package', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    $rates = $this->adapter->getRates(RateRequest::fromPackage($package), ['auto', 'usps:usps_ground_advantage']);

    expect($rates)->toHaveCount(2)
        ->and($rates->pluck('serviceCode')->all())->toBe(['auto', 'usps:usps_ground_advantage'])
        ->and($rates->every(fn (RateResponse $rate): bool => $rate->priceUnknown))->toBeTrue()
        ->and($rates->every(fn (RateResponse $rate): bool => $rate->carrier === 'Shopify'))->toBeTrue();
});

it('advertises nothing for a package that has no Shopify fulfillment order', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage(['metadata' => []]);

    expect($this->adapter->getRates(RateRequest::fromPackage($package), ['auto']))->toBeEmpty();
});

it('advertises nothing once the Shopify data source is deactivated', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    $package->shipment->dataSource->update(['active' => false]);

    expect($this->adapter->getRates(RateRequest::fromPackage($package->fresh()), ['auto']))->toBeEmpty();
});

it('splits a service code into the parts Shopify selects a rate with', function (): void {
    expect($this->adapter->splitServiceCode('usps:usps_ground_advantage'))->toBe(['usps', 'usps_ground_advantage'])
        ->and($this->adapter->splitServiceCode('auto'))->toBe([null, null])
        ->and($this->adapter->splitServiceCode('usps:'))->toBe([null, null]);
});

it('buys a label and reports the format Shopify chose', function (string $shopifyFormat, string $expectedFormat): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased($shopifyFormat)),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->success)->toBeTrue()
        ->and($response->trackingNumber)->toBe('9400111899223197428490')
        ->and($response->labelFormat)->toBe($expectedFormat)
        ->and(base64_decode($response->labelData))->toBe('LABEL-BYTES')
        ->and($response->carrier)->toBe('Shopify');
})->with([
    'PDF' => ['PDF', 'pdf'],
    'ZPL' => ['ZPL', 'zpl'],
]);

it('records no cost, because Shopify never reports what a label cost', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->cost)->toBeNull();

    $package->markShipped($response, $response->postageSource);

    expect($package->refresh()->cost)->toBeNull();
});

it('records the postage as bought through the shipment\'s Shopify data source', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->postageSource)->toBe(PostageSource::PostageDataSource)
        ->and($response->postageDataSourceId)->toBe($package->shipment->data_source_id)
        ->and($response->carrierAccountId)->toBeNull();

    $package->markShipped($response, $response->postageSource);
    $package->refresh();

    expect($package->postage_source)->toBe(PostageSource::PostageDataSource)
        ->and($package->postage_data_source_id)->toBe($package->shipment->data_source_id)
        ->and($package->carrier_account_id)->toBeNull();
});

it('sends the chosen carrier and service as a preferred rate selection', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $this->adapter->createShipment(shopifyShipRequest($package, 'usps:usps_ground_advantage'));

    Saloon::assertSent(function (GraphQL $request): bool {
        $selection = $request->body()->all()['variables']['input']['preferredRateSelection'] ?? null;

        return $selection === ['carrierCode' => 'usps', 'serviceCode' => 'usps_ground_advantage'];
    });
});

it('leaves the rate to Shopify when the auto service is chosen', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $this->adapter->createShipment(shopifyShipRequest($package));

    Saloon::assertSent(function (GraphQL $request): bool {
        $body = $request->body()->all();

        return ! isset($body['variables']['input']) || ! array_key_exists('preferredRateSelection', $body['variables']['input']);
    });
});

it('reports the validation error when Shopify rejects the purchase', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make([
            'data' => [
                'shippingLabelPurchase' => [
                    'shippingLabelPurchaseResult' => null,
                    'userErrors' => [[
                        'field' => null,
                        'code' => 'TERMS_OF_SERVICE_NOT_ACCEPTED',
                        'message' => 'Shopify Shipping terms of service have not been accepted.',
                    ]],
                ],
            ],
        ]),
    ]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('terms of service');
});

it('reports the carrier failure when the purchase job fails', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make([
            'data' => [
                'node' => [
                    'id' => 'gid://shopify/ShippingLabelPurchaseResult/1',
                    'status' => 'PURCHASE_FAILED',
                    'done' => true,
                    'errors' => [['code' => 'CARRIER_NOT_AVAILABLE', 'message' => 'The carrier is not available for this label.']],
                    'shippingLabels' => [],
                ],
            ],
        ]),
    ]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('carrier is not available');
});

it('refuses to buy a label for a shipment that did not come from Shopify', function (): void {
    seedShopifyCarrierServices();
    $package = Package::factory()->create(['shipment_id' => Shipment::factory()->create()]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('active Shopify data source');
});

it('records the carrier Shopify actually picked, not the one that was asked for', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        // Asked for USPS; Shopify bought DHL eCommerce, a carrier PolyBag has
        // no account with and no catalogued service for.
        MockResponse::make(purchasePurchased('PDF', 'DHL eCommerce')),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package, 'usps:usps_ground_advantage'));

    expect($response->carrier)->toBe('Shopify')
        ->and($response->service)->toBe('DHL eCommerce')
        ->and($response->metadata['shopify_tracking_company'])->toBe('DHL eCommerce')
        ->and($response->metadata['shopify_requested_service_code'])->toBe('usps:usps_ground_advantage');

    $package->markShipped($response, $response->postageSource);
    $package->refresh();

    expect($package->service)->toBe('DHL eCommerce')
        ->and($package->isShopifyShipped())->toBeTrue()
        ->and($package->metadata['shopify_shipping_label_id'])->toBe('gid://shopify/ShippingLabel/1');
});

it('keeps the metadata a package already carried when recording a Shopify label', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    $package->update(['metadata' => ['packed_by_station' => 'bench-3']]);

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));
    $package->markShipped($response, $response->postageSource);

    expect($package->refresh()->metadata)
        ->toHaveKey('packed_by_station', 'bench-3')
        ->toHaveKey('shopify_shipping_label_id');
});

it('keeps a purchase that Shopify completed when the label download fails', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(purchasePurchased()),
    ]);
    Http::fake(['*' => Http::response('gone', 500)]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    // Shopify charged for this label and created the fulfillment. Losing the
    // ID here would mean paying twice to ship one parcel.
    expect($response->success)->toBeFalse()
        ->and($package->refresh()->metadata['shopify_shipping_label_id'])->toBe('gid://shopify/ShippingLabel/1')
        ->and($package->metadata['shopify_label_document_url'])->not->toBeNull();
});

it('records the purchase before polling, so a timeout leaves something to resume', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    config(['services.shopify.label_poll_attempts' => 1, 'services.shopify.label_poll_interval_ms' => 0]);

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        // Still pending when the attempts run out.
        MockResponse::make(['data' => ['node' => [
            'id' => 'gid://shopify/ShippingLabelPurchaseResult/1',
            'status' => 'PENDING_PURCHASE', 'done' => false, 'errors' => [], 'shippingLabels' => [],
        ]]]),
    ]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    // Shopify is buying a label right now. Without this marker the retry would
    // send a second purchase mutation.
    expect($response->success)->toBeFalse()
        ->and($package->refresh()->metadata['shopify_purchase_result_id'])
        ->toBe('gid://shopify/ShippingLabelPurchaseResult/1');
});

it('resumes an in-flight purchase rather than buying a second label', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    $package->update(['metadata' => ['shopify_purchase_result_id' => 'gid://shopify/ShippingLabelPurchaseResult/1']]);

    Saloon::fake([MockResponse::make(purchasePurchased())]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->success)->toBeTrue()
        ->and($response->trackingNumber)->toBe('9400111899223197428490');

    Saloon::assertSentCount(1);
    Saloon::assertSent(fn (GraphQL $request): bool => ! str_contains($request->body()->all()['query'], 'shippingLabelPurchase'));

    // Superseded by the label ID, so it can never be resumed a second time.
    expect($package->refresh()->metadata)->not->toHaveKey('shopify_purchase_result_id');
});

it('allows a fresh purchase after Shopify reports the job failed', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();

    Saloon::fake([
        MockResponse::make(purchaseAccepted()),
        MockResponse::make(['data' => ['node' => [
            'id' => 'gid://shopify/ShippingLabelPurchaseResult/1',
            'status' => 'PURCHASE_FAILED', 'done' => true,
            'errors' => [['code' => 'CARRIER_NOT_AVAILABLE', 'message' => 'The carrier is not available for this label.']],
            'shippingLabels' => [],
        ]]]),
    ]);

    $this->adapter->createShipment(shopifyShipRequest($package));

    // No label was bought, so nothing should be resumed on the next attempt.
    expect($package->refresh()->metadata)->not->toHaveKey('shopify_purchase_result_id');
});

it('recovers the label already bought instead of buying a second one', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    $package->update(['metadata' => ['shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/1']]);

    Saloon::fake([
        MockResponse::make(['data' => ['shippingLabel' => purchasePurchased()['data']['node']['shippingLabels'][0]]]),
    ]);
    Http::fake(['*' => Http::response('LABEL-BYTES')]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->success)->toBeTrue()
        ->and($response->trackingNumber)->toBe('9400111899223197428490')
        ->and(base64_decode($response->labelData))->toBe('LABEL-BYTES');

    // One read, no purchase.
    Saloon::assertSentCount(1);
    Saloon::assertSent(function (GraphQL $request): bool {
        return ! str_contains($request->body()->all()['query'], 'shippingLabelPurchase');
    });
});

it('refuses to buy again when a bought label can no longer be read', function (): void {
    seedShopifyCarrierServices();
    $package = shopifyPackage();
    $package->update(['metadata' => ['shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/1']]);

    Saloon::fake([MockResponse::make(['data' => ['shippingLabel' => null]])]);

    $response = $this->adapter->createShipment(shopifyShipRequest($package));

    expect($response->success)->toBeFalse()
        ->and($response->errorMessage)->toContain('already bought')
        ->and($response->errorMessage)->toContain('Check the order in Shopify');
});

it('sends voids to the Shopify admin, which is the only place they can happen', function (): void {
    $package = shopifyPackage();

    $result = $this->adapter->cancelShipment('9400111899223197428490', $package);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('Shopify admin');
});

it('does not offer tracking, manifests, or multi-package shipments', function (): void {
    expect($this->adapter->supportsTracking())->toBeFalse()
        ->and($this->adapter->supportsManifest())->toBeFalse()
        ->and($this->adapter->supportsMultiPackage())->toBeFalse();
});

function seedShopifyCarrierServices(): void
{
    $carrier = Carrier::firstOrCreate(['name' => 'Shopify']);

    foreach ([
        'auto' => "Shopify's choice",
        'usps:usps_ground_advantage' => 'USPS Ground Advantage',
    ] as $code => $name) {
        $carrier->carrierServices()->firstOrCreate(['service_code' => $code], ['name' => $name]);
    }
}

function shopifyPackage(array $shipmentAttributes = []): Package
{
    $source = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);

    $shipment = Shipment::factory()->create(array_merge([
        'data_source_id' => $source->id,
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345'],
    ], $shipmentAttributes));

    return Package::factory()->create(['shipment_id' => $shipment->id]);
}

function shopifyShipRequest(Package $package, string $serviceCode = 'auto'): ShipRequest
{
    return ShipRequest::fromPackageAndRate($package, new RateResponse(
        carrier: 'Shopify',
        serviceCode: $serviceCode,
        serviceName: $serviceCode === 'auto' ? "Shopify's choice" : 'USPS Ground Advantage',
        price: 0.0,
        priceUnknown: true,
    ));
}

/** @return array<string, mixed> */
function purchaseAccepted(): array
{
    return [
        'data' => [
            'shippingLabelPurchase' => [
                'shippingLabelPurchaseResult' => [
                    'id' => 'gid://shopify/ShippingLabelPurchaseResult/1',
                    'status' => 'PENDING_PURCHASE',
                    'done' => false,
                    'errors' => [],
                ],
                'userErrors' => [],
            ],
        ],
    ];
}

/** @return array<string, mixed> */
function purchasePurchased(string $format = 'PDF', string $company = 'USPS'): array
{
    return [
        'data' => [
            'node' => [
                'id' => 'gid://shopify/ShippingLabelPurchaseResult/1',
                'status' => 'PURCHASED',
                'done' => true,
                'errors' => [],
                'shippingLabels' => [[
                    'id' => 'gid://shopify/ShippingLabel/1',
                    'trackingInfo' => [
                        'company' => $company,
                        'number' => '9400111899223197428490',
                        'url' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=9400111899223197428490',
                    ],
                    'shippingDocuments' => [[
                        'documentType' => 'LABEL',
                        'format' => $format,
                        'url' => 'https://cdn.shopify.test/labels/1.'.strtolower($format),
                    ]],
                ]],
            ],
        ],
    ];
}
