<?php

namespace App\Services\PackageShipping;

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingOptions;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\DataTransferObjects\Shipping\ClassifiedRate;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Exceptions\MissingDeclaredValueException;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Models\SpecialService;
use App\Services\Carriers\CarrierRegistry;
use App\Services\PostageSources\OfferStore;
use App\Services\RateQuoteLogger;
use App\Services\RateSelector;
use App\Services\RuleEvaluator;
use App\Services\ShippingRateService;
use App\Services\SpecialServiceResolver;
use Illuminate\Support\Facades\Cache;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\Statuses\RequestTimeOutException;

class EloquentPackageShippingWorkflow implements PackageShippingWorkflow
{
    public function __construct(
        private readonly ShippingRateService $shippingRateService,
        private readonly RuleEvaluator $ruleEvaluator,
        private readonly RateSelector $rateSelector,
        private readonly RateQuoteLogger $rateQuoteLogger,
        private readonly CarrierRegistry $carrierRegistry,
        private readonly OfferStore $offerStore,
    ) {}

    public function prepareRates(Package $package): PackageShippingOptions
    {
        $package->loadMissing(['shipment.shippingMethod']);

        $rates = $this->shippingRateService->getShippingRates($package->id);

        $exclusions = $this->shippingRateService->getExclusions();

        $ruleResult = $this->ruleEvaluator->evaluate($package->shipment);
        if ($ruleResult->shouldFilterRates()) {
            $rates = $rates->reject(
                fn (RateResponse $rate): bool => in_array($rate->serviceCode, $ruleResult->excludedServiceCodes, true)
            );
        }

        $deadline = $package->shipment->getDeliverByDate();
        $classified = $this->rateSelector->classify($rates, $deadline);

        // Per-rate special service visibility: which requested services will
        // actually be purchased with each rate, and which get stripped by
        // carrier-service scoping — so behavioral differences between rates
        // are never silent on the Ship page.
        $resolver = app(SpecialServiceResolver::class);
        $requestedCodes = $resolver->resolveForPackage($package);
        $serviceNames = $requestedCodes === []
            ? collect()
            : SpecialService::whereIn('code', $requestedCodes)->pluck('name', 'code');

        $labels = [];
        $descriptions = [];
        $options = [];

        foreach ($classified as $key => $classifiedRate) {
            $labels[$key] = $classifiedRate->rate->formLabel();
            $description = $classifiedRate->rate->formDescription();
            if (! $classifiedRate->isOnTime) {
                $description .= ' — LATE';
            }
            $descriptions[$key] = $description;
            $rateArray = $classifiedRate->rate->toArray();

            if ($requestedCodes !== []) {
                $appliedCodes = $resolver->resolveForPackageAndRate($package, $classifiedRate->rate);
                $toNames = fn (array $codes): array => array_values(
                    array_map(fn (string $code): string => $serviceNames->get($code, $code), $codes)
                );
                $rateArray['specialServices'] = [
                    'applied' => $toNames($appliedCodes),
                    'stripped' => $toNames(array_values(array_diff($requestedCodes, $appliedCodes))),
                ];
            }

            $options[$key] = $rateArray;
        }

        return new PackageShippingOptions(
            rateOptions: $options,
            rateOptionLabels: $labels,
            rateOptionDescriptions: $descriptions,
            deliverByDate: $deadline?->format('D, M j'),
            allRatesLate: $deadline !== null && $classified->isNotEmpty() && $classified->every(fn (ClassifiedRate $cr): bool => ! $cr->isOnTime),
            exclusions: $exclusions,
            selectedRateIndex: $this->selectedRateIndex($options, $ruleResult->preSelectedRate ?? null),
        );
    }

    /**
     * How long one package's purchase may hold the line before the lock is
     * assumed abandoned. Generous on purpose: it spans an external label call,
     * and a lock that expires mid-purchase is worse than one held too long.
     */
    private const PURCHASE_LOCK_SECONDS = 180;

    /**
     * Buy postage for exactly one attempt at a time, per package.
     *
     * The unresolved-purchase guard and the offer claim inside are two separate
     * writes, so without this two requests carrying two *different* valid
     * offers would both read a clean package, claim their own row, and buy a
     * label each. The lock is what makes "does this package already have a
     * purchase in flight?" and "claim this offer" one decision.
     *
     * Taken without waiting. Queueing behind a carrier call that may run for a
     * minute would leave a packer staring at a frozen button, and the honest
     * answer — someone is already buying this — is one they can act on.
     */
    public function ship(Package $package, PackageShippingRequest $request): PackageShippingResult
    {
        $lock = Cache::lock("package-purchase:{$package->id}", self::PURCHASE_LOCK_SECONDS);

        if (! $lock->get()) {
            return PackageShippingResult::offerUnavailable(
                'Purchase In Progress',
                'Postage for this package is already being bought. Wait for that attempt to finish before trying again.',
            );
        }

        try {
            return $this->buyPostage($package, $request);
        } finally {
            $lock->release();
        }
    }

    private function buyPostage(Package $package, PackageShippingRequest $request): PackageShippingResult
    {
        // Nothing is spent on a package that already has a purchase nobody can
        // account for. An offer consumed without the source either confirming
        // or declining may have bought a label we never recorded, and a second
        // purchase would pay for a second one.
        if (($unresolved = $this->offerStore->awaitingPurchaseConfirmation($package))->isNotEmpty()) {
            logger()->warning('Refused to buy postage while an earlier purchase is unaccounted for', [
                'package_id' => $package->id,
                'offers' => $unresolved->pluck('public_id')->all(),
            ]);

            return PackageShippingResult::offerUnavailable(
                'Earlier Purchase Unresolved',
                'A previous attempt to buy postage for this package did not report back, so a label may already exist. '
                .'Check the carrier or channel for a label on this package before buying again.',
            );
        }

        // A rate carrying an offer identifier is bought against the offer, not
        // against its description: what came back from the browser says which
        // offer, and nothing more. The carrier, service and price come off the
        // stored row, so a tampered or stale rate cannot spend one offer and
        // buy something else.
        $offer = null;
        $selectedRate = $request->selectedRate;

        if ($selectedRate->offerId !== null) {
            $inspection = $this->offerStore->inspect($package, $selectedRate->offerId);

            if ($inspection->wasRejected()) {
                return PackageShippingResult::offerUnavailable($inspection->title(), $inspection->message());
            }

            $offer = $inspection->offer;

            if ($rejection = $this->unsupportedDispatch($offer) ?? $this->accountNoLongerResolves($offer, $package)) {
                return $rejection;
            }

            $selectedRate = $this->rateFromOffer($offer, $selectedRate);
        }

        $this->rateQuoteLogger->markSelected($package->id, $selectedRate);

        try {
            $adapter = $this->carrierRegistry->get($selectedRate->carrier);
            $shipRequest = ShipRequest::fromPackageAndRate(
                $package,
                $selectedRate,
                $request->labelFormat,
                $request->labelDpi,
            );

            // Everything that can fail locally fails before the offer is
            // claimed. A customs-weight prompt is a round trip through the
            // operator, and consuming the offer on the way out would leave the
            // confirmed retry with nothing to buy.
            if ($request->requireCustomsWeightOverride && $this->requiresCustomsWeightOverride($shipRequest, $request->overrideCustomsWeights)) {
                return PackageShippingResult::customsWeightOverrideRequired();
            }

            if ($request->overrideCustomsWeights) {
                $shipRequest = $shipRequest->withScaledCustomsWeights();
            }

            // The one-way door, immediately before the money is spent. The
            // inspection above was advisory; this is the claim that a
            // concurrent attempt loses.
            if ($offer !== null) {
                $claim = $this->offerStore->redeem($package, $offer->public_id);

                if ($claim->wasRejected()) {
                    return PackageShippingResult::offerUnavailable($claim->title(), $claim->message());
                }

                $offer = $claim->offer;
            }

            $response = $adapter->createShipment($shipRequest);

            if (! $response->success) {
                // The source answered and declined, so the offer is settled
                // rather than ambiguous: nothing was bought, and the package is
                // free to be quoted again.
                $this->resolveOfferAsFailed($offer, $response->errorMessage ?? 'The carrier rejected the shipment.');

                return PackageShippingResult::failed('Shipping Error', $response->errorMessage ?? 'Failed to create shipment.');
            }

            $this->recordPurchaseAgainstOffer($offer, $response->trackingNumber);

            $package->markShipped($response, $response->postageSource, $request->userId);

            return PackageShippingResult::shipped($response, $selectedRate, $package);
        } catch (MissingDeclaredValueException $e) {
            return PackageShippingResult::failed('Declared Value Required', $e->getMessage());
        } catch (RequestTimeOutException) {
            logger()->error('Carrier API timeout', [
                'carrier' => $request->selectedRate->carrier,
                'package_id' => $package->id,
            ]);

            return PackageShippingResult::failed(
                'Carrier Timeout',
                "The {$request->selectedRate->carrier} API is not responding. Please try again in a few moments.",
            );
        } catch (RequestException $e) {
            logger()->error('Carrier API error', [
                'carrier' => $request->selectedRate->carrier,
                'package_id' => $package->id,
                'error' => $e->getMessage(),
            ]);

            return PackageShippingResult::failed(
                'Carrier Error',
                "Unable to connect to {$request->selectedRate->carrier}. Please check your connection and try again.",
            );
        } catch (\RuntimeException $e) {
            return PackageShippingResult::stateConflict($e->getMessage());
        } catch (\Exception $e) {
            logger()->error('Shipping error', [
                'package_id' => $package->id,
                'error' => $e->getMessage(),
            ]);

            return PackageShippingResult::failed('Shipping Error', 'An unexpected error occurred. Please try again.');
        }
    }

    public function autoShip(Package $package, PackageAutoShippingRequest $request): PackageShippingResult
    {
        try {
            $selectedRate = $this->selectedRateForAutoShip($package);

            if (! $selectedRate) {
                $result = PackageShippingResult::failed('Shipping Error', 'No shipping rates available for this package.');
                $this->cleanupPackage($package, $request, $result);

                return $result;
            }

            $result = $this->ship(
                $package,
                new PackageShippingRequest(
                    selectedRate: $selectedRate,
                    labelFormat: $request->labelFormat,
                    labelDpi: $request->labelDpi,
                    requireCustomsWeightOverride: false,
                    userId: $request->userId,
                ),
            );

            $this->cleanupPackage($package, $request, $result);

            return $result;
        } catch (RequestTimeOutException) {
            logger()->error('AutoShip timeout', ['package_id' => $package->id]);
            $result = PackageShippingResult::failed('Carrier Timeout', 'The carrier API is not responding. Please try again in a few moments.');
            $this->cleanupPackage($package, $request, $result);

            return $result;
        } catch (RequestException $e) {
            logger()->error('AutoShip carrier error', ['package_id' => $package->id, 'error' => $e->getMessage()]);
            $result = PackageShippingResult::failed('Carrier Error', 'Unable to connect to the carrier. Please try again.');
            $this->cleanupPackage($package, $request, $result);

            return $result;
        } catch (\RuntimeException $e) {
            logger()->warning('AutoShip race condition', ['package_id' => $package->id, 'error' => $e->getMessage()]);

            return PackageShippingResult::stateConflict($e->getMessage());
        } catch (\Exception $e) {
            logger()->error('AutoShip error', ['package_id' => $package->id, 'error' => $e->getMessage()]);
            $result = PackageShippingResult::failed('Auto Ship Error', 'An unexpected error occurred. Please try again.');
            $this->cleanupPackage($package, $request, $result);

            return $result;
        }
    }

    /**
     * Refuse an offer this workflow cannot honestly dispatch.
     *
     * Purchase still routes through `CarrierRegistry` by carrier name, which is
     * correct for exactly one case: an offer bought on one of our own carrier
     * accounts, where the carrier of record and the adapter to call are the
     * same thing. They are not the same thing for channel postage — an Amazon
     * offer carried by OnTrac has to be bought from Amazon, and looking up
     * "OnTrac" would find a direct adapter we do not have and have no account
     * with.
     *
     * The real fix is quoting and purchasing on `PostageSourceOperations`,
     * dispatched by the offer's source instance with its own purchase context.
     * That is `amazon-buy-shipping/03`, and this guard is what it deletes.
     * Until then a channel offer fails loudly rather than reaching the wrong
     * carrier.
     */
    private function unsupportedDispatch(ShippingOffer $offer): ?PackageShippingResult
    {
        if ($offer->postage_source === PostageSource::CarrierAccount) {
            return null;
        }

        logger()->error('An offer was selected that no purchase path can dispatch yet', [
            'package_id' => $offer->package_id,
            'offer' => $offer->public_id,
            'postage_source' => $offer->postage_source->value,
            'carrier' => $offer->carrier,
        ]);

        return PackageShippingResult::offerUnavailable(
            'Rate Not Purchasable',
            'This rate was quoted through a sales channel, and buying it needs a purchase path that is not built yet. '
            .'Choose a rate from one of your own carrier accounts.',
        );
    }

    /**
     * Refuse an offer whose carrier account is no longer the one that would buy.
     *
     * An offer records which account quoted it, but the purchase cannot yet be
     * told to use that account: adapters resolve their own through
     * `ResolvesCarrierAccount`, from the package's location and client, and
     * `ShipRequest` has nowhere to name one. Usually the two agree. They stop
     * agreeing when scopes are edited between quote and purchase, or when rate
     * shopping quoted several accounts for one carrier and priority has since
     * moved — and then the label is bought on an account that never offered
     * that price.
     *
     * So this compares rather than plumbs, using the same resolution the
     * adapter will use rather than a copy of its rules, and refuses when they
     * diverge. Passing the account through `ShipRequest` is the real answer and
     * belongs with the interface work in `amazon-buy-shipping/03`.
     */
    private function accountNoLongerResolves(ShippingOffer $offer, Package $package): ?PackageShippingResult
    {
        if ($offer->carrier_account_id === null) {
            return null;
        }

        $carrierId = Carrier::where('name', $offer->carrier)->value('id');

        $resolved = $carrierId === null ? null : CarrierAccount::resolveForShipment(
            $carrierId,
            $package->location_id,
            $package->shipment?->client_id,
        )->first();

        if ($resolved?->id === $offer->carrier_account_id) {
            return null;
        }

        logger()->warning('Refused an offer whose carrier account is no longer the one that would be used', [
            'package_id' => $package->id,
            'offer' => $offer->public_id,
            'quoted_on_account' => $offer->carrier_account_id,
            'would_buy_on_account' => $resolved?->id,
        ]);

        return PackageShippingResult::offerUnavailable(
            'Carrier Account Changed',
            'This rate was quoted on a carrier account that is no longer the one this package would ship on. '
            .'Get rates again so the price matches the account that will be billed.',
        );
    }

    /**
     * The rate as the server knows it, for an offer the browser only named.
     *
     * Carrier, service, price and rate metadata all come off the stored offer;
     * the delivery commitment and transit time are carried through because they
     * are display text that cannot change what is bought.
     *
     * The metadata matters more than it looks. FedEx reads
     * `metadata['serviceType']` with no fallback and USPS reads `mailClass`,
     * `rateIndicator` and `processingCategory` the same way, so an offer that
     * dropped it would not buy the wrong label — it would fail to buy one at
     * all. It is restored from the offer rather than from the request for the
     * same reason the price is: the source stated it, and the browser does not
     * get to restate it.
     */
    private function rateFromOffer(ShippingOffer $offer, RateResponse $selected): RateResponse
    {
        return new RateResponse(
            carrier: $offer->carrier,
            serviceCode: $offer->service_code ?? '',
            serviceName: $offer->service_name ?? '',
            price: (float) ($offer->price ?? 0.0),
            deliveryCommitment: $selected->deliveryCommitment,
            deliveryDate: $selected->deliveryDate,
            transitTime: $selected->transitTime,
            metadata: $offer->rate_metadata ?? [],
            priceUnknown: $offer->price === null,
            offerId: $offer->public_id,
        );
    }

    /**
     * Settle an offer the source declined.
     *
     * Only ever called on a response — a reply from the source is proof that
     * nothing was bought. An exception is not: a timeout leaves the offer
     * unresolved on purpose, so the next attempt is blocked until someone
     * establishes whether a label exists.
     */
    private function resolveOfferAsFailed(?ShippingOffer $offer, string $reason): void
    {
        if ($offer !== null) {
            $this->offerStore->recordFailure($offer, $reason);
        }
    }

    /**
     * Tie the offer to the purchase it paid for, if nothing else did first.
     *
     * A backstop, not the primary record: an adapter that talks to the source
     * should stamp the source's own identifier the moment the source confirms,
     * because the window where a purchase exists upstream and not here opens
     * before this line is reached. A tracking number is the weaker reference
     * that stops the offer looking unresolved when nobody set a better one.
     */
    private function recordPurchaseAgainstOffer(?ShippingOffer $offer, ?string $trackingNumber): void
    {
        if ($offer === null || $trackingNumber === null) {
            return;
        }

        $this->offerStore->recordPurchase($offer, $trackingNumber);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rateOptions
     */
    private function selectedRateIndex(array $rateOptions, ?RateResponse $preSelectedRate): ?int
    {
        if (! $preSelectedRate) {
            return $rateOptions === [] ? null : 0;
        }

        foreach ($rateOptions as $key => $rateArray) {
            if ($rateArray['carrier'] === $preSelectedRate->carrier && $rateArray['serviceCode'] === $preSelectedRate->serviceCode) {
                return $key;
            }
        }

        return $rateOptions === [] ? null : 0;
    }

    private function requiresCustomsWeightOverride(ShipRequest $shipRequest, bool $overrideCustomsWeights): bool
    {
        if ($overrideCustomsWeights
            || ! $shipRequest->toAddress->requiresCustomsDeclaration()
            || empty($shipRequest->customsItems)) {
            return false;
        }

        $totalCustomsWeight = collect($shipRequest->customsItems)->sum(fn ($item): float => $item->weight * $item->quantity);

        return $totalCustomsWeight > $shipRequest->packageData->weight;
    }

    private function selectedRateForAutoShip(Package $package): ?RateResponse
    {
        $package->loadMissing(['packageItems.product', 'packageItems.shipmentItem', 'shipment.shippingMethod']);

        $ruleResult = $this->ruleEvaluator->evaluate($package->shipment, $package);

        if ($ruleResult->hasPreSelectedRate()) {
            $adapter = $this->carrierRegistry->get($ruleResult->preSelectedRate->carrier);

            return $adapter->resolvePreSelectedRate($ruleResult->preSelectedRate, $package);
        }

        $options = $this->prepareRates($package);

        if ($options->selectedRateIndex === null || ! isset($options->rateOptions[$options->selectedRateIndex])) {
            return null;
        }

        return RateResponse::fromArray($options->rateOptions[$options->selectedRateIndex]);
    }

    private function cleanupPackage(Package $package, PackageAutoShippingRequest $request, PackageShippingResult $result): void
    {
        if (! $request->cleanupOnFailure || $result->success || $result->leavePackageIntact) {
            return;
        }

        if ($package->exists && $package->status !== PackageStatus::Shipped) {
            $package->packageItems()->delete();
            $package->delete();
        }
    }
}
