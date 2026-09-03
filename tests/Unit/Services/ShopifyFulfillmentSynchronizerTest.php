<?php

use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Shipment;
use App\Services\ShopifyLabelVoidSynchronizer;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

beforeEach(function (): void {
    $this->synchronizer = app(ShopifyLabelVoidSynchronizer::class);
});

it('un-ships a package whose label was voided in the Shopify admin', function (): void {
    $package = shippedShopifyPackage();

    Saloon::fake([MockResponse::make(fulfillmentState('LABEL_VOIDED'))]);

    $result = $this->synchronizer->sync();

    $package->refresh();

    expect($result)->toBe(['checked' => 1, 'voided' => 1, 'failed' => 0])
        ->and($package->status)->toBe(PackageStatus::Unshipped)
        ->and($package->tracking_number)->toBeNull()
        ->and($package->carrier)->toBeNull()
        ->and($package->label_data)->toBeNull();
});

it('records why the package was un-shipped', function (): void {
    $package = shippedShopifyPackage();

    Saloon::fake([MockResponse::make(fulfillmentState('LABEL_VOIDED'))]);

    $this->synchronizer->sync();

    $audit = AuditLog::where('auditable_id', $package->id)->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['reason'])->toBe('Label voided in Shopify')
        ->and($audit->metadata['tracking_number'])->toBe('9400111899223197428490');
});

it('drops the label identifiers so a re-ship buys a new label', function (): void {
    $package = shippedShopifyPackage([
        'metadata' => [
            'shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/1',
            'shopify_purchase_result_id' => 'gid://shopify/ShippingLabelPurchaseResult/1',
            'shopify_label_document_url' => 'https://cdn.shopify.test/labels/1.pdf',
            'packed_by_station' => 'bench-3',
        ],
    ]);

    Saloon::fake([MockResponse::make(fulfillmentState('LABEL_VOIDED'))]);

    $this->synchronizer->sync();

    // Left behind, these would let the purchase path "recover" a voided label.
    $metadata = $package->refresh()->metadata;

    expect($metadata)->not->toHaveKey('shopify_shipping_label_id');
    expect($metadata)->not->toHaveKey('shopify_purchase_result_id');
    expect($metadata)->not->toHaveKey('shopify_label_document_url');
    expect($metadata)->toHaveKey('packed_by_station', 'bench-3');
});

it('leaves a package alone while its label is still live', function (): void {
    $package = shippedShopifyPackage();

    Saloon::fake([MockResponse::make(fulfillmentState('LABEL_PURCHASED'))]);

    $result = $this->synchronizer->sync();

    expect($result['voided'])->toBe(0)
        ->and($package->refresh()->status)->toBe(PackageStatus::Shipped);
});

it('treats a fulfillment belonging to another package as no answer at all', function (): void {
    $package = shippedShopifyPackage();

    // A partially fulfilled order carries fulfillments for other packages;
    // reading one of those as ours would un-ship a parcel that is in transit.
    Saloon::fake([MockResponse::make(fulfillmentState('LABEL_VOIDED', '9999999999999999999999'))]);

    $result = $this->synchronizer->sync();

    expect($result['voided'])->toBe(0)
        ->and($package->refresh()->status)->toBe(PackageStatus::Shipped);
});

it('counts a failed check without touching the package', function (): void {
    $package = shippedShopifyPackage();

    Saloon::fake([MockResponse::make(['errors' => [['message' => 'Throttled']]])]);

    $result = $this->synchronizer->sync();

    expect($result)->toBe(['checked' => 1, 'voided' => 0, 'failed' => 1])
        ->and($package->refresh()->status)->toBe(PackageStatus::Shipped);
});

it('never checks packages that were not shipped through Shopify', function (): void {
    Package::factory()->create([
        'shipment_id' => Shipment::factory()->create(),
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223197428490',
        'status' => PackageStatus::Shipped,
    ]);

    expect($this->synchronizer->candidates())->toBeEmpty();
});

it('leaves a delivered package alone, since its label was already used', function (): void {
    shippedShopifyPackage(['delivered_at' => now()]);

    expect($this->synchronizer->candidates())->toBeEmpty();
});

it('stops asking about labels too old to be voided', function (): void {
    // These packages never report as delivered — Shopify Shipping sends no
    // tracking updates back — so without the ship-date bound every label ever
    // bought would stay in the poll for good.
    shippedShopifyPackage(['shipped_at' => now()->subDays(31)]);

    expect($this->synchronizer->candidates())->toBeEmpty();

    shippedShopifyPackage(['shipped_at' => now()->subDays(29)]);

    expect($this->synchronizer->candidates())->toHaveCount(1);
});

it('honours a configured void-check window', function (): void {
    config(['services.shopify.label_void_check_days' => 2]);
    shippedShopifyPackage(['shipped_at' => now()->subDays(3)]);

    expect($this->synchronizer->candidates())->toBeEmpty();
});

function shippedShopifyPackage(array $attributes = []): Package
{
    $source = createShopifyDataSource([], ['oauth_access_token' => 'shpat_test_token']);

    $shipment = Shipment::factory()->create([
        'data_source_id' => $source->id,
        'metadata' => ['shopify_fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/12345'],
    ]);

    return Package::factory()->create(array_merge([
        'shipment_id' => $shipment->id,
        'carrier' => 'USPS',
        'service' => 'Ground Advantage',
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => $source->id,
        'tracking_number' => '9400111899223197428490',
        'status' => PackageStatus::Shipped,
        'shipped_at' => now(),
        'label_data' => base64_encode('LABEL-BYTES'),
        'metadata' => ['shopify_shipping_label_id' => 'gid://shopify/ShippingLabel/1'],
    ], $attributes));
}

/** @return array<string, mixed> */
function fulfillmentState(string $displayStatus, string $trackingNumber = '9400111899223197428490'): array
{
    return [
        'data' => [
            'fulfillmentOrder' => [
                'id' => 'gid://shopify/FulfillmentOrder/12345',
                'status' => 'CLOSED',
                'fulfillments' => [
                    'nodes' => [[
                        'id' => 'gid://shopify/Fulfillment/1',
                        'status' => 'SUCCESS',
                        'displayStatus' => $displayStatus,
                        'trackingInfo' => [['number' => $trackingNumber]],
                    ]],
                ],
            ],
        ],
    ];
}
