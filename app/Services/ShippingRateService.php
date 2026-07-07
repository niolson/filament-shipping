<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\ServiceCapability;
use App\Exceptions\Carriers\CarrierRateFetchException;
use App\Exceptions\NoActiveCarrierServicesException;
use App\Models\CarrierService;
use App\Models\CarrierServiceSpecialService;
use App\Models\Package;
use App\Models\ShippingMethod;
use App\Models\SpecialService;
use App\Services\Carriers\CarrierRegistry;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Illuminate\Support\Collection;
use Saloon\Http\Senders\GuzzleSender;

class ShippingRateService
{
    /**
     * Carriers excluded from the last getShippingRates() call due to prohibited services.
     * Keyed by carrier name, value is the human-readable reason.
     *
     * @var array<string, string>
     */
    private array $exclusions = [];

    /**
     * Returns carriers excluded from the last getShippingRates() call.
     * Each entry is ['carrier' => string, 'reason' => string].
     *
     * @return array<int, array{carrier: string, reason: string}>
     */
    public function getExclusions(): array
    {
        return array_map(
            fn ($carrier, $reason) => ['carrier' => $carrier, 'reason' => $reason],
            array_keys($this->exclusions),
            $this->exclusions,
        );
    }

    /**
     * Get shipping rates for a package from all applicable carriers.
     *
     * @return Collection<int, RateResponse>
     *
     * @throws NoActiveCarrierServicesException
     */
    public function getShippingRates(int $packageId): Collection
    {
        $package = Package::with(['packageItems', 'shipment.shippingMethod'])
            ->findOrFail($packageId);

        $shipment = $package->shipment;
        $shippingMethod = $shipment->shippingMethod;
        $rateRequest = RateRequest::fromPackage($package);

        $resolver = app(SpecialServiceResolver::class);
        $methodCodes = $resolver->methodCodesByMode($shippingMethod);
        $productCodes = $resolver->resolveProductRequiredCodes($package)->keys()->all();
        $requiredCodes = array_values(array_unique([...$methodCodes['required'], ...$productCodes]));
        $defaultCodes = array_values(array_diff($methodCodes['default'], $requiredCodes));

        $this->exclusions = [];
        $scopeMap = $this->loadServiceScopes([...$requiredCodes, ...$defaultCodes]);
        $serviceNames = SpecialService::whereIn('code', [...$requiredCodes, ...$defaultCodes])
            ->pluck('name', 'code');

        // Build the list of carriers to query
        $carrierTasks = [];

        if ($shippingMethod) {
            $activeCarrierServices = $this->getActiveCarrierServices($shippingMethod);

            if ($activeCarrierServices->isEmpty()) {
                throw new NoActiveCarrierServicesException($shippingMethod->name);
            }

            logger()->debug('ShippingRateService: Getting rates', [
                'package_id' => $packageId,
                'shipping_method' => $shippingMethod->name,
                'active_carrier_services_count' => $activeCarrierServices->count(),
                'carrier_services' => $activeCarrierServices->pluck('service_code', 'name')->toArray(),
            ]);

            $carrierServicesByCarrier = $activeCarrierServices->groupBy('carrier_id');

            foreach ($carrierServicesByCarrier as $services) {
                $task = $this->buildCarrierTask(
                    $services->first()->carrier->name,
                    $services,
                    $requiredCodes,
                    $defaultCodes,
                    $scopeMap,
                    $serviceNames,
                    $rateRequest->destinationCountry,
                );

                if ($task) {
                    $carrierTasks[] = $task;
                }
            }
        } else {
            logger()->debug('ShippingRateService: No shipping method assigned, querying all configured carriers', [
                'package_id' => $packageId,
            ]);

            foreach (array_keys(app(CarrierRegistry::class)->getConfiguredAdapters()) as $name) {
                $task = $this->buildCarrierTask(
                    $name,
                    collect(),
                    $requiredCodes,
                    $defaultCodes,
                    $scopeMap,
                    $serviceNames,
                    $rateRequest->destinationCountry,
                );

                if ($task) {
                    $carrierTasks[] = $task;
                }
            }
        }

        $rateOptions = $this->fetchRatesConcurrently($carrierTasks, $rateRequest);

        try {
            app(RateQuoteLogger::class)->logRates($packageId, $rateOptions);
        } catch (\Exception $e) {
            logger()->warning('Failed to log rate quotes', [
                'package_id' => $packageId,
                'error' => $e->getMessage(),
            ]);
        }

        return $rateOptions;
    }

    /**
     * Fetch rates from multiple carriers concurrently using a shared Guzzle sender.
     *
     * @param  array<int, array{name: string, serviceCodes: array<string>, specialServiceCodes: array<string>}>  $carrierTasks
     * @return Collection<int, RateResponse>
     */
    private function fetchRatesConcurrently(array $carrierTasks, RateRequest $rateRequest): Collection
    {
        $rateOptions = collect();
        $preparedRequests = [];
        $taskMeta = [];

        $registry = app(CarrierRegistry::class);

        $shipDateService = app(ShipDateService::class);

        // Phase 1: Prepare all requests (authenticate connectors, build request bodies)
        foreach ($carrierTasks as $task) {
            $carrierName = $task['name'];
            $serviceCodes = $task['serviceCodes'];

            try {
                if (! $registry->has($carrierName)) {
                    logger()->warning("ShippingRateService: Unknown carrier {$carrierName}");

                    continue;
                }

                $adapter = $registry->get($carrierName);

                if (! $adapter->isConfigured()) {
                    logger()->warning("ShippingRateService: {$carrierName} is not configured");

                    continue;
                }

                $shipDate = $shipDateService->getShipDate($carrierName, $rateRequest->locationId);
                $carrierRateRequest = $rateRequest
                    ->withShipDate($shipDate)
                    ->withSpecialServiceCodes($task['specialServiceCodes']);

                $prepared = $adapter->prepareRateRequest($carrierRateRequest, $serviceCodes);

                if (! $prepared) {
                    // No API call needed — fall back to synchronous getRates (e.g., mock rates)
                    $rates = $adapter->getRates($carrierRateRequest, $serviceCodes);
                    $rateOptions->push(...$rates);

                    continue;
                }

                $preparedRequests[$carrierName] = $prepared;
                $taskMeta[$carrierName] = ['adapter' => $adapter, 'serviceCodes' => $serviceCodes, 'rateRequest' => $carrierRateRequest];
            } catch (CarrierRateFetchException $e) {
                $loggedException = $e->getPrevious() ?? $e;

                logger()->error("ShippingRateService: {$carrierName} rate fetch failed", [
                    'carrier' => $carrierName,
                    'exception' => $loggedException::class,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Exception $e) {
                logger()->error("ShippingRateService: {$carrierName} prepare error", [
                    'carrier' => $carrierName,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fall back to synchronous sends when only one carrier needs an API call (no
        // concurrency overhead needed) or when any request has a fake/mock response set
        // (Saloon faking bypasses the sender, so the shared GuzzleSender can't handle it).
        $hasFakeResponses = collect($preparedRequests)->contains(
            fn (PreparedRateRequest $p) => $p->pendingRequest->hasFakeResponse()
        );

        if (count($preparedRequests) <= 1 || $hasFakeResponses) {
            foreach ($preparedRequests as $carrierName => $prepared) {
                $meta = $taskMeta[$carrierName];

                try {
                    $rates = $meta['adapter']->getRates($meta['rateRequest'], $meta['serviceCodes']);
                    $rateOptions->push(...$rates);
                } catch (CarrierRateFetchException $e) {
                    $loggedException = $e->getPrevious() ?? $e;

                    logger()->error("ShippingRateService: {$carrierName} rate fetch failed", [
                        'carrier' => $carrierName,
                        'exception' => $loggedException::class,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $rateOptions;
        }

        // Phase 2: Send all requests concurrently through a shared Guzzle sender
        $sharedSender = new GuzzleSender;
        $promises = [];

        foreach ($preparedRequests as $carrierName => $prepared) {
            logger()->debug("ShippingRateService: Sending async rate request to {$carrierName}");
            $promises[$carrierName] = $sharedSender->sendAsync($prepared->pendingRequest);
        }

        $results = PromiseUtils::settle($promises)->wait();

        // Phase 3: Parse responses
        foreach ($results as $carrierName => $result) {
            $meta = $taskMeta[$carrierName];

            if ($result['state'] === 'fulfilled') {
                try {
                    $rates = $meta['adapter']->parseRateResponse(
                        $result['value'],
                        $meta['rateRequest'],
                        $meta['serviceCodes'],
                    );

                    logger()->debug("ShippingRateService: Got {$carrierName} rates", [
                        'rates_count' => $rates->count(),
                    ]);

                    $rateOptions->push(...$rates);
                } catch (\Exception $e) {
                    logger()->error("ShippingRateService: {$carrierName} parse error", [
                        'carrier' => $carrierName,
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                logger()->error("ShippingRateService: {$carrierName} request failed", [
                    'error' => $result['reason']?->getMessage() ?? 'Unknown error',
                ]);
            }
        }

        return $rateOptions;
    }

    /**
     * Build the rate task for one carrier, applying capability and carrier-service
     * scope checks. Hard-required codes (shipping-method required mode + product
     * compliance) drop carrier services that aren't scoped for them and exclude
     * the carrier entirely when nothing survives (or the carrier prohibits /
     * hasn't implemented the code). Default-mode codes never drop a carrier
     * service — the code is stripped from the carrier's request instead.
     *
     * Returns null (recording the reason in $this->exclusions) when the carrier
     * is excluded.
     *
     * @param  Collection<int, CarrierService>  $services  Empty when no shipping method is assigned
     * @param  array<int, string>  $requiredCodes
     * @param  array<int, string>  $defaultCodes
     * @param  array<string, array<int, array<int, array<int, string>|null>>>  $scopeMap
     * @param  Collection<string, string>  $serviceNames
     * @return array{name: string, serviceCodes: array<int, string>, specialServiceCodes: array<int, string>}|null
     */
    private function buildCarrierTask(
        string $carrierName,
        Collection $services,
        array $requiredCodes,
        array $defaultCodes,
        array $scopeMap,
        Collection $serviceNames,
        string $destinationCountry,
    ): ?array {
        $registry = app(CarrierRegistry::class);
        $specialServiceCodes = [];

        $adapter = $registry->has($carrierName) ? $registry->get($carrierName) : null;

        foreach ($requiredCodes as $code) {
            if ($adapter && $adapter->serviceCapability($code) !== ServiceCapability::Supported) {
                // Prohibited and NotImplemented both exclude: a hard-required
                // service the carrier can't actually apply must not be skipped.
                $this->exclusions[$carrierName] = $carrierName.' does not support '.$serviceNames->get($code, $code).'.';

                return null;
            }

            $carrierScopes = $this->scopesForCarrier($scopeMap, $code, $services);

            if ($carrierScopes !== null) {
                $services = $services->filter(
                    fn (CarrierService $service): bool => $this->scopeAllows($carrierScopes, $service->id, $destinationCountry)
                );

                if ($services->isEmpty()) {
                    $this->exclusions[$carrierName] = $carrierName.' has no services that support '.$serviceNames->get($code, $code).' for this destination.';

                    return null;
                }
            }

            $specialServiceCodes[] = $code;
        }

        foreach ($defaultCodes as $code) {
            if ($adapter) {
                $capability = $adapter->serviceCapability($code);

                if ($capability === ServiceCapability::Prohibited) {
                    $this->exclusions[$carrierName] = $carrierName.' does not support '.$serviceNames->get($code, $code).'.';

                    return null;
                }

                if ($capability === ServiceCapability::NotImplemented) {
                    continue;
                }
            }

            $carrierScopes = $this->scopesForCarrier($scopeMap, $code, $services);

            if ($carrierScopes !== null && $services->doesntContain(
                fn (CarrierService $service): bool => $this->scopeAllows($carrierScopes, $service->id, $destinationCountry)
            )) {
                logger()->debug("ShippingRateService: {$carrierName} has no services scoped for default service {$code}, requesting rates without it");

                continue;
            }

            $specialServiceCodes[] = $code;
        }

        return [
            'name' => $carrierName,
            'serviceCodes' => $services->pluck('service_code')->values()->all(),
            'specialServiceCodes' => $specialServiceCodes,
        ];
    }

    /**
     * Carrier-service scope rows for one code, limited to this carrier.
     * Returns null when the code has no rows for any of this carrier's services —
     * the code is unscoped for this carrier and only the carrier-wide capability
     * check applies. Also null when there is no carrier-service list to filter
     * (no shipping method assigned).
     *
     * @param  array<string, array<int, array<int, array<int, string>|null>>>  $scopeMap
     * @param  Collection<int, CarrierService>  $services
     * @return array<int, array<int, string>|null>|null carrier_service_id => restricted_countries
     */
    private function scopesForCarrier(array $scopeMap, string $code, Collection $services): ?array
    {
        if ($services->isEmpty() || ! isset($scopeMap[$code])) {
            return null;
        }

        $carrierId = $services->first()->carrier_id;

        return $scopeMap[$code][$carrierId] ?? null;
    }

    /**
     * @param  array<int, array<int, string>|null>  $carrierScopes  carrier_service_id => restricted_countries
     */
    private function scopeAllows(array $carrierScopes, int $carrierServiceId, string $destinationCountry): bool
    {
        if (! array_key_exists($carrierServiceId, $carrierScopes)) {
            return false;
        }

        $restrictedCountries = $carrierScopes[$carrierServiceId];

        return empty($restrictedCountries) || in_array($destinationCountry, $restrictedCountries, true);
    }

    /**
     * Load carrier_service_special_service rows for the given codes into a
     * lookup of code => carrier_id => carrier_service_id => restricted_countries.
     *
     * @param  array<int, string>  $codes
     * @return array<string, array<int, array<int, array<int, string>|null>>>
     */
    private function loadServiceScopes(array $codes): array
    {
        if (empty($codes)) {
            return [];
        }

        $codesByServiceId = SpecialService::whereIn('code', $codes)->pluck('code', 'id');

        if ($codesByServiceId->isEmpty()) {
            return [];
        }

        $rows = CarrierServiceSpecialService::whereIn('special_service_id', $codesByServiceId->keys())->get();

        $carrierIdsByServiceId = CarrierService::whereIn('id', $rows->pluck('carrier_service_id'))
            ->pluck('carrier_id', 'id');

        $map = [];

        foreach ($rows as $row) {
            $code = $codesByServiceId[$row->special_service_id];
            $carrierId = (int) $carrierIdsByServiceId[$row->carrier_service_id];

            $map[$code][$carrierId][(int) $row->carrier_service_id] = $row->restricted_countries;
        }

        return $map;
    }

    /**
     * Get active carrier services for a shipping method.
     * Filters to only include services where both the carrier and the service are active.
     *
     * @return Collection<int, CarrierService>
     */
    private function getActiveCarrierServices(ShippingMethod $shippingMethod): Collection
    {
        return $shippingMethod->carrierServices()
            ->active()
            ->withActiveCarrier()
            ->with('carrier')
            ->get();
    }
}
