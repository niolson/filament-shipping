<?php

use App\Http\Integrations\Amazon\AmazonSpApiConnector;
use App\Http\Integrations\Amazon\Requests\ConfirmShipment;
use App\Http\Integrations\Amazon\Requests\SearchOrders;
use App\Models\Channel;
use App\Models\ChannelAlias;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Shipment;
use App\Services\SettingsService;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\PackageExportService;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

function amazonOrdersResponse(array $orders = [], ?string $nextToken = null): MockResponse
{
    $body = [
        'orders' => $orders,
    ];

    if ($nextToken !== null) {
        $body['pagination'] = ['nextToken' => $nextToken];
    }

    return MockResponse::make($body);
}

function amazonConfirmShipmentResponse(): MockResponse
{
    return MockResponse::make([], 200);
}

function sampleAmazonOrder(string $orderId = '111-2222222-3333333'): array
{
    return [
        'orderId' => $orderId,
        'orderStatus' => 'Unshipped',
        'recipient' => [
            'deliveryAddress' => [
                'name' => 'Jane Smith',
                'addressLine1' => '456 Oak Ave',
                'addressLine2' => null,
                'city' => 'Seattle',
                'stateOrRegion' => 'WA',
                'postalCode' => '98101',
                'countryCode' => 'US',
                'phone' => '2065551234',
            ],
        ],
        'buyer' => [
            'buyerEmail' => 'test@marketplace.amazon.com',
        ],
        'orderItems' => sampleAmazonOrderItems(),
    ];
}

function sampleAmazonOrderItems(): array
{
    return [
        [
            'product' => [
                'sellerSku' => 'SKU-100',
                'title' => 'Test Product',
            ],
            'quantityOrdered' => 3,
            'fulfillment' => ['quantityFulfilled' => 0],
            'proceeds' => [
                'breakdowns' => [
                    [
                        'type' => 'ITEM',
                        'subtotal' => ['amount' => '75.00', 'currencyCode' => 'USD'],
                    ],
                ],
            ],
        ],
        [
            'product' => [
                'sellerSku' => 'SKU-200',
                'title' => 'Another Product',
            ],
            'quantityOrdered' => 1,
            'fulfillment' => ['quantityFulfilled' => 0],
            'proceeds' => [
                'breakdowns' => [
                    [
                        'type' => 'ITEM',
                        'subtotal' => ['amount' => '10.00', 'currencyCode' => 'USD'],
                    ],
                ],
            ],
        ],
    ];
}

beforeEach(function (): void {
    Setting::updateOrCreate(['key' => 'require_mfa'], ['value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    // Key format: amazon_sp_api_access_token_ + md5(refresh_token)
    Cache::put('amazon_sp_api_access_token_'.md5('test-refresh-token'), 'test-access-token', 3600);

    $this->dataSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'name' => 'Amazon',
        'settings' => ['channel_name' => 'Amazon', 'marketplace_id' => 'ATVPDKIKX0DER'],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);
});

it('imports amazon orders into shipments table with metadata', function (): void {
    $channel = tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([sampleAmazonOrder()]),
    ]);

    $source = new AmazonSource([
        'source_type' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'shipping_method' => null,
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(1);
    expect($result->itemsCreated)->toBe(2);
    expect($result->errors)->toBeEmpty();

    $shipment = Shipment::where('shipment_reference', '111-2222222-3333333')->first();
    expect($shipment)->not->toBeNull();
    expect($shipment->first_name)->toBe('Jane');
    expect($shipment->last_name)->toBe('Smith');
    expect($shipment->city)->toBe('Seattle');
    expect($shipment->state_or_province)->toBe('WA');
    expect($shipment->postal_code)->toBe('98101');
    expect($shipment->country)->toBe('US');
    expect($shipment->email)->toBe('test@marketplace.amazon.com');
    expect($shipment->channel_id)->toBe($channel->id);
    expect($shipment->source_record_id)->toBe('111-2222222-3333333');

    // Metadata stored correctly
    expect($shipment->metadata)->toBeArray();
    expect($shipment->metadata['amazon_order_id'])->toBe('111-2222222-3333333');

    // Items created
    expect($shipment->shipmentItems)->toHaveCount(2);
});

it('exports package to amazon as shipment confirmation', function (): void {
    $channel = Channel::factory()->create(['name' => 'Amazon']);

    $exportSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'name' => 'Amazon Export',
        'settings' => [
            'channel_name' => 'Amazon',
            'marketplace_id' => 'ATVPDKIKX0DER',
            'export_enabled' => true,
            'export_field_mapping' => [
                'tracking_number' => 'tracking_number',
                'carrier' => 'carrier',
                'shipment_reference' => 'shipment_reference',
                'amazon_order_id' => 'amazon_order_id',
            ],
        ],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);

    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '111-2222222-3333333',
        'metadata' => [
            'amazon_order_id' => '111-2222222-3333333',
        ],
    ]);

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK123',
        'carrier' => 'USPS',
        'service' => 'Priority Mail',
        'exported' => false,
    ]);

    Saloon::fake([
        ConfirmShipment::class => amazonConfirmShipmentResponse(),
    ]);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    expect($result->success)->toBeTrue();
    expect($result->destinationsAttempted)->toBe(1);
    expect($result->destinationsSucceeded)->toBe(1);
    expect($package->fresh()->exported)->toBeTrue();

    Saloon::assertSent(function (ConfirmShipment $request) {
        $body = $request->body()->all();

        return ($body['packageDetail']['trackingNumber'] ?? '') === 'TRACK123'
            && ($body['packageDetail']['carrierCode'] ?? '') === 'USPS';
    });
});

it('handles package without amazon metadata gracefully in export', function (): void {
    $channel = Channel::factory()->create(['name' => 'Amazon']);

    $exportSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'name' => 'Amazon Export',
        'settings' => [
            'channel_name' => 'Amazon',
            'marketplace_id' => 'ATVPDKIKX0DER',
            'export_enabled' => true,
            'export_field_mapping' => [
                'tracking_number' => 'tracking_number',
                'carrier' => 'carrier',
                'shipment_reference' => 'shipment_reference',
                'amazon_order_id' => 'amazon_order_id',
            ],
        ],
        'secret_settings' => [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
        ],
    ]);

    $shipment = Shipment::factory()->create([
        'channel_id' => $channel->id,
        'data_source_id' => $exportSource->id,
        'shipment_reference' => '111-0000000-0000000',
        'metadata' => null,
    ]);

    $package = Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'tracking_number' => 'TRACK456',
        'carrier' => 'USPS',
        'exported' => false,
    ]);

    $service = new PackageExportService;
    $result = $service->exportPackage($package);

    // Should fail gracefully (no Amazon order ID)
    expect($result->success)->toBeFalse();
    expect($result->errors)->not->toBeEmpty();
    expect($result->errors[0])->toContain('Amazon order ID');
});

it('imports multiple pages of amazon orders', function (): void {
    $channel = tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    // Sequential mocks: SearchOrders(page1) → SearchOrders(page2)
    // Items are embedded in the order response — no separate fetch needed
    Saloon::fake([
        amazonOrdersResponse(
            [sampleAmazonOrder('111-1111111-1111111')],
            nextToken: 'token_page2'
        ),
        amazonOrdersResponse(
            [sampleAmazonOrder('111-2222222-2222222')],
        ),
    ]);

    $source = new AmazonSource([
        'source_type' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'shipping_method' => null,
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(2);
    expect(Shipment::where('shipment_reference', '111-1111111-1111111')->exists())->toBeTrue();
    expect(Shipment::where('shipment_reference', '111-2222222-2222222')->exists())->toBeTrue();
});

it('validates amazon configuration requires credentials', function (): void {
    $source = new AmazonSource([
        'source_type' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    expect(fn () => $source->validateConfiguration())
        ->toThrow(InvalidArgumentException::class, 'client credentials');
});

it('validates amazon configuration requires mfa to be enabled', function (): void {
    Setting::where('key', 'require_mfa')->delete();
    app(SettingsService::class)->clearCache();

    $source = new AmazonSource([
        'source_type' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    expect(fn () => $source->validateConfiguration())
        ->toThrow(RuntimeException::class, 'Multi-factor authentication must be enabled');
});

it('imports sandbox order with full quantities even when already fulfilled', function (): void {
    $channel = tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    app(SettingsService::class)->set('sandbox_mode', true);

    // Sandbox order where items are already fulfilled
    $order = sampleAmazonOrder();
    $order['orderItems'] = [
        [
            'product' => [
                'sellerSku' => 'SKU-100',
                'title' => 'Fulfilled Item',
            ],
            'quantityOrdered' => 3,
            'fulfillment' => ['quantityFulfilled' => 3],
            'proceeds' => [
                'breakdowns' => [
                    [
                        'type' => 'ITEM',
                        'subtotal' => ['amount' => '30.00', 'currencyCode' => 'USD'],
                    ],
                ],
            ],
        ],
    ];

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([$order]),
    ]);

    $source = new AmazonSource([
        'source_type' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'shipping_method' => null,
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(1);

    $shipment = Shipment::where('shipment_reference', '111-2222222-3333333')->first();
    // In sandbox mode, full quantityOrdered (3) is used, not 0
    expect((float) $shipment->value)->toBe(30.0);

    $lineItem = $shipment->shipmentItems->first();
    expect($lineItem->quantity)->toBe(3);
    expect((float) $lineItem->value)->toBe(10.0);
});

it('calculates item unit prices correctly from proceeds breakdowns', function (): void {
    $channel = tap(Channel::factory()->create(['name' => 'Amazon']), fn ($c) => ChannelAlias::create(['reference' => 'Amazon', 'channel_id' => $c->id]));

    $order = sampleAmazonOrder();
    $order['orderItems'] = [
        [
            'product' => [
                'sellerSku' => 'SKU-300',
                'title' => 'Bulk Item',
            ],
            'quantityOrdered' => 4,
            'fulfillment' => ['quantityFulfilled' => 1],
            'proceeds' => [
                'breakdowns' => [
                    [
                        'type' => 'ITEM',
                        'subtotal' => ['amount' => '40.00', 'currencyCode' => 'USD'],
                    ],
                ],
            ],
        ],
    ];

    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse([$order]),
    ]);

    $source = new AmazonSource([
        'source_type' => AmazonSource::class,
        'enabled' => true,
        'channel_name' => 'Amazon',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'shipping_method' => null,
        'lookback_days' => 30,
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $result = ShipmentImportService::forSource($source, $this->dataSource)->import();

    expect($result->shipmentsCreated)->toBe(1);

    $shipment = Shipment::where('shipment_reference', '111-2222222-3333333')->first();
    // Unit price = 40/4 = 10, qty remaining = 3, total = 30
    expect((float) $shipment->value)->toBe(30.0);

    $lineItem = $shipment->shipmentItems->first();
    expect($lineItem->quantity)->toBe(3);
    expect((float) $lineItem->value)->toBe(10.0);
});

it('validates when per-source client_id and client_secret are present without tenant credentials', function (): void {
    $source = new AmazonSource([
        'client_id' => 'per_source_client_id',
        'client_secret' => 'per_source_client_secret',
        'refresh_token' => 'per_source_refresh_token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'channel_name' => 'Amazon',
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    $source->validateConfiguration();
    expect(true)->toBeTrue();
});

it('refreshes an OAuth-connected Amazon source through polybag-connect', function (): void {
    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => 'broker-secret',
        'services.oauth.instance_id' => 'test-instance',
    ]);
    Cache::forget('amazon_sp_api_access_token_'.md5('oauth-refresh-token'));

    Http::fake([
        'connect.polybag.app/oauth/sp-api/refresh' => Http::response([
            'access_token' => 'broker-access-token',
            'expires_in' => 3600,
        ]),
    ]);
    Saloon::fake([
        SearchOrders::class => amazonOrdersResponse(),
    ]);

    $source = new AmazonSource([
        'auth_mode' => 'authorization_code',
        'refresh_token' => 'oauth-refresh-token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'channel_name' => 'Amazon',
        'lookback_days' => 30,
    ]);

    $source->validateConfiguration();
    $source->fetchShipments();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://connect.polybag.app/oauth/sp-api/refresh'
            && $request['refresh_token'] === 'oauth-refresh-token'
            && $request['instance_id'] === 'test-instance'
            && $request['signature'] === hash_hmac('sha256', 'oauth-refresh-token', 'broker-secret');
    });
});

it('persists a rotated Amazon refresh token returned by polybag-connect', function (): void {
    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => 'broker-secret',
        'services.oauth.instance_id' => 'test-instance',
    ]);
    $dataSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => [
            'auth_mode' => 'authorization_code',
            'marketplace_id' => 'ATVPDKIKX0DER',
            'channel_name' => 'Amazon',
        ],
        'secret_settings' => ['refresh_token' => 'old-refresh-token'],
    ]);
    Cache::forget('amazon_sp_api_access_token_'.md5('old-refresh-token'));
    Cache::forget('amazon_sp_api_access_token_'.md5('rotated-refresh-token'));

    Http::fake([
        'connect.polybag.app/oauth/sp-api/refresh' => Http::response([
            'access_token' => 'broker-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'expires_in' => 3600,
        ]),
    ]);
    Saloon::fake([
        amazonOrdersResponse(nextToken: 'second-page'),
        amazonOrdersResponse(),
    ]);

    $source = app(DataSourceFactory::class)->make($dataSource);
    $source->fetchShipments();

    expect($dataSource->refresh()->secret('refresh_token'))->toBe('rotated-refresh-token')
        ->and(Cache::get('amazon_sp_api_access_token_'.md5('old-refresh-token')))->toBeNull()
        ->and(Cache::get('amazon_sp_api_access_token_'.md5('rotated-refresh-token')))->toBe('broker-access-token');
    Http::assertSentCount(1);
    Saloon::assertSentCount(2);
});

it('reuses the rotated-token cache when Amazon headers are resolved again', function (): void {
    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => 'broker-secret',
        'services.oauth.instance_id' => 'test-instance',
    ]);
    $dataSource = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => ['auth_mode' => 'authorization_code'],
        'secret_settings' => ['refresh_token' => 'old-refresh-token'],
    ]);
    Cache::forget('amazon_sp_api_access_token_'.md5('old-refresh-token'));
    Cache::forget('amazon_sp_api_access_token_'.md5('rotated-refresh-token'));

    Http::fake([
        'connect.polybag.app/oauth/sp-api/refresh' => Http::response([
            'access_token' => 'broker-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'expires_in' => 3600,
        ]),
    ]);

    $connector = new class(baseUrl: 'https://sellingpartnerapi-na.amazon.com', sandboxUrl: 'https://sandbox.sellingpartnerapi-na.amazon.com', clientId: '', clientSecret: '', refreshToken: 'old-refresh-token', authMode: 'authorization_code', dataSourceId: $dataSource->id) extends AmazonSpApiConnector
    {
        public function accessTokenHeader(): string
        {
            return $this->defaultHeaders()['x-amz-access-token'];
        }
    };

    expect($connector->accessTokenHeader())->toBe('broker-access-token')
        ->and($connector->accessTokenHeader())->toBe('broker-access-token');
    Http::assertSentCount(1);
});

it('fails validation when only per-source client_id but no client_secret and no tenant credentials', function (): void {
    $source = new AmazonSource([
        'client_id' => 'per_source_client_id',
        'refresh_token' => 'per_source_refresh_token',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'channel_name' => 'Amazon',
        'export' => ['enabled' => false, 'field_mapping' => []],
    ]);

    // client_secret is absent, so the source has no usable credentials.
    expect(fn () => $source->validateConfiguration())
        ->toThrow(InvalidArgumentException::class, 'client credentials');
});
