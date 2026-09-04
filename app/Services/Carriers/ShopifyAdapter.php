<?php

namespace App\Services\Carriers;

use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PostageSource;
use App\Enums\ServiceCapability;
use App\Enums\ServiceEvidence;
use App\Exceptions\Carriers\ShopifyLabelPurchaseException;
use App\Models\CarrierService;
use App\Models\DataSource;
use App\Models\Package;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\ShopifyShippingLabelService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Buys postage through Shopify Shipping rather than through a carrier account
 * of our own — the way to reach USPS Connect eCommerce rates without an NSA.
 *
 * Shopify is not a carrier, and since ADR-0002 decision 7 this no longer has to
 * pretend otherwise: it implements the offer seam only. Voiding, tracking and
 * manifest eligibility follow the postage source and live on
 * `ShopifyPostageSource`; carrier policy belongs to whichever carrier Shopify
 * picks, which is not known until the purchase comes back.
 *
 * What remains is unlike the other adapters by necessity. Shopify's Admin API
 * has no rate-quote operation and exposes no price on a purchased label, so:
 *
 * - rates are advertised, not quoted (`priceUnknown`), and the cost recorded on
 *   the package is left null rather than invented;
 * - no purchased service is reported either, so the package records the service
 *   as `unknown` and keeps what was asked for as a requested preference;
 * - there is no rate API at all, so this implements none of `AsyncRateQuoting`
 *   and is quoted synchronously through `getRates()`;
 * - only shipments imported from an active Shopify data source are eligible,
 *   since a purchase is keyed to a Shopify fulfillment order.
 *
 * Service codes are `carrier:service` pairs for Shopify's
 * `preferredRateSelection` (`usps:usps_ground_advantage`), or the bare code
 * `auto` to let Shopify pick the rate the way its admin would.
 */
class ShopifyAdapter implements CarrierAdapterInterface
{
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
     * Nothing bought here can be promised a special service.
     *
     * Not modesty about what USPS or UPS would do — the point is that Shopify
     * chooses the carrier and the rate itself, after the purchase, so any
     * promise made at quote time is one we have no way to keep. ADR-0002
     * decision 8 puts this judgement on the offer for exactly that reason:
     * asked as carrier policy it has no honest answer, because there is no
     * carrier yet.
     *
     * A shipment that hard-requires the service drops this offer, visibly. One
     * that merely prefers it keeps the offer and goes without.
     */
    public function offerCapability(string $serviceCode): ServiceCapability
    {
        return ServiceCapability::Unguaranteed;
    }

    /**
     * No cap to report for the same reason: the carrier that would insure the
     * parcel is not known until Shopify has bought the label.
     */
    public function offerDeclaredValueCap(): ?float
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

    public function createShipment(ShipRequest $request): ShipResponse
    {
        $package = $request->packageId ? Package::with('shipment.dataSource')->find($request->packageId) : null;

        if (! $package) {
            return ShipResponse::failure('Shopify Shipping labels can only be bought for a saved package.');
        }

        [$carrierCode, $serviceCode] = $this->splitServiceCode($request->selectedRate->serviceCode);

        $labelService = app(ShopifyShippingLabelService::class);

        try {
            $label = $labelService->purchase($package, $request, $carrierCode, $serviceCode);
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
            // What Shopify actually did, not what was asked for. Shopify may
            // ignore preferredRateSelection outright, and it can pick a carrier
            // PolyBag has no account with at all — DHL eCommerce, Canada Post —
            // so the carrier it reports is the only trustworthy record.
            carrier: $label->trackingCompany ?? ($carrierCode === null ? null : Str::upper($carrierCode)),
            // Shopify reports no purchased service, before or after the buy —
            // `ShippingLabel` has no service, service code, rate or price. What
            // was asked for is kept as the requested preference, which is audit
            // metadata and not the service value. ADR-0003 decisions 5 and 7.
            service: null,
            requestedService: $serviceCode === null ? null : $request->selectedRate->serviceName,
            serviceEvidence: ServiceEvidence::Unknown,
            labelData: $label->labelData,
            labelOrientation: 'portrait',
            labelFormat: $label->labelFormat,
            labelDpi: $request->labelDpi,
            shipDate: $request->shipDate,
            // The postage was bought on the merchant's Shopify account, not on
            // one of ours — so the provenance is the data source the shipment
            // came from, which purchasing has just proved resolves.
            postageSource: PostageSource::PostageDataSource,
            postageDataSourceId: $labelService->dataSourceFor($package)?->id,
            metadata: array_filter([
                'shopify_shipping_label_id' => $label->shippingLabelId,
                'shopify_tracking_company' => $label->trackingCompany,
                'shopify_customs_form_url' => $label->customsFormUrl,
                'shopify_label_document_url' => $label->labelDocumentUrl,
                // The raw code beside the requested preference the package
                // records, so a selection Shopify silently ignored stays visible.
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
