<?php

use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use Saloon\Enums\Method;

it('posts to the Shipping v2 rates endpoint', function (): void {
    $request = new GetShippingRates;

    expect($request->resolveEndpoint())->toBe('/shipping/v2/shipments/rates')
        ->and($request->getMethod())->toBe(Method::POST);
});

it('passes the payload through untouched', function (): void {
    $payload = [
        'channelDetails' => ['channelType' => 'AMAZON', 'amazonOrderDetails' => ['orderId' => '111-2223334-4445556']],
        'packages' => [['packageClientReferenceId' => 'pkg-1']],
    ];

    expect((new GetShippingRates($payload))->body()->all())->toBe($payload);
});

// Amazon defaults this header to AmazonShipping_UK when it is omitted, which is wrong for
// every marketplace we serve -- and it fails silently rather than erroring, so the default
// is asserted rather than assumed.
it('sends the US shipping business by default', function (): void {
    expect((new GetShippingRates)->headers()->get('x-amzn-shipping-business-id'))
        ->toBe('AmazonShipping_US');
});

it('allows the shipping business to be overridden per marketplace', function (): void {
    expect((new GetShippingRates([], 'AmazonShipping_UK'))->headers()->get('x-amzn-shipping-business-id'))
        ->toBe('AmazonShipping_UK');
});
