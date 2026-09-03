<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use Illuminate\Support\Collection;
use Saloon\Http\Response;

/**
 * An offer source with a rate API, quoted off the packer's critical path.
 *
 * The two methods are one operation split across `ShippingRateService`'s
 * concurrency phases, so they are declared together: anything that can hand back
 * a request to send must be able to read the reply. Splitting them let a source
 * prepare a request whose response nobody could parse, and the rates vanished
 * with no error — which is why this is its own contract rather than two methods
 * on {@see CarrierAdapterInterface}.
 *
 * Not every offer source has a rate API. Shopify Shipping advertises rates it
 * cannot quote and implements none of this; `ShippingRateService` asks such a
 * source synchronously instead.
 */
interface AsyncRateQuoting
{
    /**
     * Prepare a rate API request for async sending.
     *
     * Returns a PreparedRateRequest containing a PendingRequest ready to send,
     * or null when no API call is needed after all — mock rates, or an adapter
     * that turns out not to be configured. A null sends the caller to
     * {@see CarrierAdapterInterface::getRates()} instead.
     *
     * @param  array<string>  $serviceCodes
     */
    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest;

    /**
     * Parse a rate API response into rate options.
     *
     * @param  array<string>  $serviceCodes
     * @return Collection<int, RateResponse>
     */
    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection;
}
