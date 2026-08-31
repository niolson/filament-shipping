<?php

namespace App\Services\Carriers;

use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Exceptions\Carriers\ShopifyLabelPurchaseException;
use App\Models\CarrierService;
use App\Models\DataSource;
use App\Models\Package;
use App\Services\Carriers\Concerns\HasDefaultServiceCapabilities;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\ShopifyShippingLabelService;
use Illuminate\Support\Collection;
use Saloon\Http\Response;

/**
 * Buys postage through Shopify Shipping rather than through a carrier account
 * of our own — the way to reach USPS Connect eCommerce rates without an NSA.
 *
 * This carrier is unlike the others by necessity. Shopify's Admin API has no
 * rate-quote operation, exposes no price on a purchased label, and offers no
 * way to void one, so:
 *
 * - rates are advertised, not quoted (`priceUnknown`), and the cost recorded on
 *   the package is left null rather than invented;
 * - only shipments imported from an active Shopify data source are eligible,
 *   since a purchase is keyed to a Shopify fulfillment order;
 * - voiding has to happen in the Shopify admin.
 *
 * Service codes are `carrier:service` pairs for Shopify's
 * `preferredRateSelection` (`usps:usps_ground_advantage`), or the bare code
 * `auto` to let Shopify pick the rate the way its admin would.
 */
class ShopifyAdapter implements CarrierAdapterInterface
{
    use HasDefaultServiceCapabilities;

    public const CARRIER_NAME = 'Shopify';

    /** Service code that leaves rate selection to Shopify. */
    public const AUTO_SERVICE_CODE = 'auto';

    public function getCarrierName(): string
    {
        return self::CARRIER_NAME;
    }

    /**
     * Configured when any active Shopify data source exists — that data source
     * carries both the credentials and the fulfillment orders labels are bought
     * against.
     */
    public function isConfigured(): bool
    {
        return DataSource::query()
            ->where('active', true)
            ->where('source_type', ShopifySource::class)
            ->exists();
    }

    /**
     * Shopify has no rate API, so there is never a request to send.
     */
    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
    {
        return null;
    }

    /**
     * Advertise the catalogued Shopify services for packages that can actually
     * use them. The prices aren't known until the label is bought, so each rate
     * is flagged `priceUnknown` and sorts behind every real quote.
     *
     * @param  array<string>  $serviceCodes
     * @return Collection<int, RateResponse>
     */
    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        if ($serviceCodes === [] || ! $request->packageId) {
            return collect();
        }

        $package = Package::with('shipment.dataSource')->find($request->packageId);

        if (! $package || ! app(ShopifyShippingLabelService::class)->canPurchaseFor($package)) {
            return collect();
        }

        $names = CarrierService::query()
            ->whereHas('carrier', fn ($query) => $query->where('name', self::CARRIER_NAME))
            ->whereIn('service_code', $serviceCodes)
            ->pluck('name', 'service_code');

        return collect($serviceCodes)
            ->filter(fn (string $code): bool => $names->has($code))
            ->map(fn (string $code): RateResponse => new RateResponse(
                carrier: self::CARRIER_NAME,
                serviceCode: $code,
                serviceName: $names->get($code),
                price: 0.0,
                priceUnknown: true,
            ))
            ->values();
    }

    /**
     * @param  array<string>  $serviceCodes
     * @return Collection<int, RateResponse>
     */
    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
    {
        return collect();
    }

    public function createShipment(ShipRequest $request): ShipResponse
    {
        $package = $request->packageId ? Package::with('shipment.dataSource')->find($request->packageId) : null;

        if (! $package) {
            return ShipResponse::failure('Shopify Shipping labels can only be bought for a saved package.');
        }

        [$carrierCode, $serviceCode] = $this->splitServiceCode($request->selectedRate->serviceCode);

        try {
            $label = app(ShopifyShippingLabelService::class)->purchase($package, $request, $carrierCode, $serviceCode);
        } catch (ShopifyLabelPurchaseException $e) {
            logger()->error('Shopify label purchase failed', [
                'package_id' => $package->id,
                'service_code' => $request->selectedRate->serviceCode,
                'error' => $e->getMessage(),
            ]);

            return ShipResponse::failure($e->getMessage());
        }

        if (! $label->trackingNumber) {
            return ShipResponse::failure('Shopify bought the label but returned no tracking number.');
        }

        // Deliberately no cost: Shopify bills the merchant for the label and
        // exposes no price through the API, and a fabricated 0.00 would read as
        // a free label everywhere the cost is reported.
        return new ShipResponse(
            success: true,
            trackingNumber: $label->trackingNumber,
            cost: null,
            carrier: self::CARRIER_NAME,
            // What Shopify actually did, not what was asked for. Shopify may
            // ignore preferredRateSelection outright, and it can pick a carrier
            // PolyBag has no account with at all — DHL eCommerce, Canada Post —
            // so the carrier it reports is the only trustworthy record.
            service: $label->trackingCompany ?? $request->selectedRate->serviceName,
            labelData: $label->labelData,
            labelOrientation: 'portrait',
            labelFormat: $label->labelFormat,
            labelDpi: $request->labelDpi,
            shipDate: $request->shipDate,
            metadata: array_filter([
                'shopify_shipping_label_id' => $label->shippingLabelId,
                'shopify_tracking_company' => $label->trackingCompany,
                'shopify_customs_form_url' => $label->customsFormUrl,
                'shopify_label_document_url' => $label->labelDocumentUrl,
                // Kept so a service Shopify silently overrode stays visible.
                'shopify_requested_service_code' => $request->selectedRate->serviceCode,
            ], fn (?string $value): bool => filled($value)),
        );
    }

    /**
     * Split a `carrier:service` code into its Shopify parts. The `auto` code —
     * and anything without a carrier prefix — leaves the choice to Shopify.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function splitServiceCode(string $serviceCode): array
    {
        if (! str_contains($serviceCode, ':')) {
            return [null, null];
        }

        [$carrierCode, $service] = explode(':', $serviceCode, 2);

        return filled($carrierCode) && filled($service) ? [$carrierCode, $service] : [null, null];
    }

    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
    {
        return CancelResponse::failure(
            'Shopify Shipping labels cannot be voided through the API. Cancel this label in the Shopify admin, then void it here.'
        );
    }

    public function supportsTracking(): bool
    {
        return false;
    }

    public function trackShipment(Package $package): TrackShipmentResponse
    {
        return TrackShipmentResponse::unsupported();
    }

    /**
     * A Shopify purchase covers one fulfillment order with one label.
     */
    public function supportsMultiPackage(): bool
    {
        return false;
    }

    public function supportsManifest(): bool
    {
        return false;
    }

    /**
     * Nothing to resolve: a Shopify rate has no variants and no price to fill in.
     */
    public function resolvePreSelectedRate(RateResponse $rate, Package $package): RateResponse
    {
        return $rate->priceUnknown ? $rate : new RateResponse(
            carrier: $rate->carrier,
            serviceCode: $rate->serviceCode,
            serviceName: $rate->serviceName,
            price: $rate->price,
            deliveryCommitment: $rate->deliveryCommitment,
            deliveryDate: $rate->deliveryDate,
            transitTime: $rate->transitTime,
            metadata: $rate->metadata,
            priceUnknown: true,
        );
    }
}
