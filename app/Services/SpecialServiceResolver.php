<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\RateResponse;
use App\Exceptions\MissingDeclaredValueException;
use App\Models\CarrierService;
use App\Models\CarrierServiceSpecialService;
use App\Models\Package;
use App\Models\ShippingMethod;
use App\Models\SpecialService;
use Illuminate\Support\Collection;

/**
 * Resolves which special service codes apply to a package, combining
 * shipping-method policy (the shipping_method_special_service pivot) with
 * product-compliance requirements (contains_alcohol / hazmat_class).
 */
class SpecialServiceResolver
{
    /**
     * All special service codes to send to carriers for this package:
     * shipping-method required + default codes plus product-compliance codes.
     *
     * @return array<int, string>
     */
    public function resolveForPackage(Package $package): array
    {
        $package->loadMissing('shipment.shippingMethod');

        $byMode = $this->methodCodesByMode($package->shipment?->shippingMethod);

        $codes = collect($byMode['required'])
            ->merge($byMode['default'])
            ->merge($this->resolveProductRequiredCodes($package)->keys())
            ->unique();

        return self::normalizeCodes($codes)->values()->all();
    }

    /**
     * Special service codes to send when purchasing a label for a selected
     * rate. Default-mode codes are filtered against the selected carrier
     * service's scope, mirroring what rate shopping stripped, so the purchase
     * request matches the quoted rate. Hard-required codes (required mode +
     * product compliance) are never stripped — a rate that couldn't satisfy
     * them was excluded at rate time.
     *
     * @return array<int, string>
     */
    public function resolveForPackageAndRate(Package $package, RateResponse $rate): array
    {
        $package->loadMissing('shipment.shippingMethod');

        $byMode = $this->methodCodesByMode($package->shipment?->shippingMethod);
        $requiredCodes = collect($byMode['required'])
            ->merge($this->resolveProductRequiredCodes($package)->keys())
            ->unique();
        $defaultCodes = collect($byMode['default'])->diff($requiredCodes);

        $carrierService = CarrierService::where('service_code', $rate->serviceCode)
            ->whereHas('carrier', fn ($query) => $query->where('name', $rate->carrier))
            ->first();

        if ($carrierService && $defaultCodes->isNotEmpty()) {
            $shipment = $package->shipment;
            $destinationCountry = $shipment->validated_country ?? $shipment->country ?? 'US';

            $defaultCodes = $defaultCodes->filter(
                fn (string $code): bool => $this->carrierServiceAllows($carrierService, $code, $destinationCountry)
            );
        }

        return self::normalizeCodes($requiredCodes->merge($defaultCodes)->unique())->values()->all();
    }

    /**
     * Drop codes superseded by a stronger variant in the same set: adult
     * signature implies signature, so both are never sent together.
     *
     * @param  Collection<int, string>  $codes
     * @return Collection<int, string>
     */
    public static function normalizeCodes(Collection $codes): Collection
    {
        if ($codes->contains('adult_signature_required')) {
            return $codes->reject(fn (string $code): bool => $code === 'signature_required');
        }

        return $codes;
    }

    /**
     * Shipping-method special service codes grouped by pivot mode.
     * Available-mode codes are operator-selected, never auto-applied, so they
     * are not resolved here. Inactive services are skipped even when a stale
     * pivot row still references them — an unwired service must never gate
     * carriers or reach an adapter.
     *
     * @return array{required: array<int, string>, default: array<int, string>}
     */
    public function methodCodesByMode(?ShippingMethod $shippingMethod): array
    {
        if (! $shippingMethod) {
            return ['required' => [], 'default' => []];
        }

        $services = $shippingMethod->specialServices()
            ->where('special_services.active', true)
            ->whereIn('shipping_method_special_service.mode', ['required', 'default'])
            ->get();

        return [
            'required' => $services->where('pivot.mode', 'required')->pluck('code')->values()->all(),
            'default' => $services->where('pivot.mode', 'default')->pluck('code')->values()->all(),
        ];
    }

    /**
     * Product-compliance codes required by the package's contents, keyed by
     * code => triggering product id. Only codes whose SpecialService is active
     * are enforced — compliance services stay inactive until their
     * carrier-specific adapter work is wired, so flagged products keep
     * shipping as before until each service is switched on.
     *
     * @return Collection<string, int>
     */
    public function resolveProductRequiredCodes(Package $package): Collection
    {
        $package->loadMissing('packageItems.product');

        $candidates = collect();

        foreach ($package->packageItems as $packageItem) {
            $product = $packageItem->product;

            if (! $product) {
                continue;
            }

            if ($product->contains_alcohol && ! $candidates->has('alcohol')) {
                $candidates->put('alcohol', $product->id);
            }

            if ($product->hazmat_class && ! $candidates->has($product->hazmat_class->value)) {
                $candidates->put($product->hazmat_class->value, $product->id);
            }
        }

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $activeCodes = SpecialService::where('active', true)
            ->whereIn('code', $candidates->keys()->push('adult_signature_required')->unique())
            ->pluck('code')
            ->all();

        $candidates = $candidates->only($activeCodes);

        // Alcohol requires an adult signature at delivery — pair it automatically
        // so the compliance backstop can't be forgotten at the method level.
        // Paired only after the active filter: deactivating the alcohol service
        // fully disables alcohol compliance, including the paired signature.
        if ($candidates->has('alcohol')
            && ! $candidates->has('adult_signature_required')
            && in_array('adult_signature_required', $activeCodes, true)) {
            $candidates->put('adult_signature_required', $candidates->get('alcohol'));
        }

        return $candidates;
    }

    /**
     * Per-code config values to send alongside the resolved codes.
     * Currently only declared_value carries config (the amount).
     *
     * @param  array<int, string>  $codes
     * @return array<string, array<string, mixed>>
     *
     * @throws MissingDeclaredValueException when declared_value resolves but no amount can be derived
     */
    public function configForPackage(Package $package, array $codes): array
    {
        $config = [];

        if (in_array('declared_value', $codes, true)) {
            $amount = $this->declaredValueForPackage($package);

            if ($amount === null) {
                throw new MissingDeclaredValueException($package->id);
            }

            $config['declared_value'] = ['amount' => $amount, 'currency' => 'USD'];
        }

        return $config;
    }

    /**
     * Declared value for one package. Single-package shipments use the
     * shipment-level value first; multi-package shipments skip it (declaring
     * the full shipment value on every label would over-declare) and use the
     * per-package item sum. Null when no usable value exists — callers must
     * surface that to the operator rather than guessing.
     */
    public function declaredValueForPackage(Package $package): ?float
    {
        $package->loadMissing(['shipment.packages', 'packageItems.shipmentItem']);
        $shipment = $package->shipment;

        if (! $shipment) {
            return null;
        }

        $shipmentValue = (float) ($shipment->value ?? 0);

        if ($shipmentValue > 0 && $shipment->packages->count() <= 1) {
            return round($shipmentValue, 2);
        }

        $itemSum = $package->packageItems->sum(
            fn ($packageItem): float => (float) ($packageItem->shipmentItem?->value ?? 0) * (int) ($packageItem->quantity ?? 1)
        );

        return $itemSum > 0 ? round($itemSum, 2) : null;
    }

    /**
     * Whether one specific carrier service may carry the given special service
     * code. Mirrors the rate-time scope rules: a code with no scope rows for
     * any of the carrier's services is unrestricted for that carrier; once
     * scoped, this exact carrier service needs a row whose restricted_countries
     * (when set) includes the destination.
     */
    private function carrierServiceAllows(CarrierService $carrierService, string $code, string $destinationCountry): bool
    {
        $specialServiceId = SpecialService::where('code', $code)->value('id');

        if (! $specialServiceId) {
            return true;
        }

        $carrierRows = CarrierServiceSpecialService::where('special_service_id', $specialServiceId)
            ->whereIn(
                'carrier_service_id',
                CarrierService::where('carrier_id', $carrierService->carrier_id)->select('id'),
            )
            ->get();

        if ($carrierRows->isEmpty()) {
            return true;
        }

        $row = $carrierRows->firstWhere('carrier_service_id', $carrierService->id);

        if (! $row) {
            return false;
        }

        return empty($row->restricted_countries)
            || in_array($destinationCountry, $row->restricted_countries, true);
    }
}
