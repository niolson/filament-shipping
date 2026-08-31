<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\Package;
use App\Services\LabelReferenceResolver;
use App\Services\ShipDateService;
use App\Services\SpecialServiceResolver;
use Carbon\CarbonImmutable;

readonly class ShipRequest
{
    /**
     * @param  array<int, CustomsItem>  $customsItems
     * @param  array<int, string>  $specialServiceCodes
     * @param  array<string, array<string, mixed>>  $specialServiceConfig  Per-code config values (e.g. declared_value amount)
     * @param  array<int, string>  $references  Identifiers to print on the label, longest-lived first; carriers truncate to their own limits
     */
    public function __construct(
        public AddressData $fromAddress,
        public AddressData $toAddress,
        public PackageData $packageData,
        public RateResponse $selectedRate,
        public array $customsItems = [],
        public string $labelFormat = 'pdf',
        public ?int $labelDpi = null,
        public array $specialServiceCodes = [],
        public ?int $locationId = null,
        public ?int $clientId = null,
        public ?CarbonImmutable $shipDate = null,
        public array $specialServiceConfig = [],
        public array $references = [],
        public ?int $packageId = null,
    ) {}

    public function hasSpecialService(string $code): bool
    {
        return in_array($code, $this->specialServiceCodes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function specialServiceConfig(string $code): array
    {
        return $this->specialServiceConfig[$code] ?? [];
    }

    public function withoutSpecialService(string $code): self
    {
        return $this->withSpecialServiceCodes(
            array_values(array_diff($this->specialServiceCodes, [$code])),
        );
    }

    /**
     * @param  array<int, string>  $codes
     */
    public function withSpecialServiceCodes(array $codes): self
    {
        return new self(
            fromAddress: $this->fromAddress,
            toAddress: $this->toAddress,
            packageData: $this->packageData,
            selectedRate: $this->selectedRate,
            customsItems: $this->customsItems,
            labelFormat: $this->labelFormat,
            labelDpi: $this->labelDpi,
            specialServiceCodes: $codes,
            locationId: $this->locationId,
            clientId: $this->clientId,
            shipDate: $this->shipDate,
            specialServiceConfig: $this->specialServiceConfig,
            references: $this->references,
            packageId: $this->packageId,
        );
    }

    /**
     * Scale customs item weights proportionally so their total matches the package weight.
     */
    public function withScaledCustomsWeights(): self
    {
        if (empty($this->customsItems)) {
            return $this;
        }

        $totalCustomsWeight = collect($this->customsItems)->sum(fn ($item) => $item->weight * $item->quantity);
        $packageWeight = $this->packageData->weight;

        if ($totalCustomsWeight <= $packageWeight || $totalCustomsWeight == 0) {
            return $this;
        }

        $scale = $packageWeight / $totalCustomsWeight;

        $scaledItems = array_map(
            fn (CustomsItem $item) => new CustomsItem(
                description: $item->description,
                quantity: $item->quantity,
                unitValue: $item->unitValue,
                weight: round($item->weight * $scale, 2),
                hsTariffNumber: $item->hsTariffNumber,
                countryOfOrigin: $item->countryOfOrigin,
            ),
            $this->customsItems,
        );

        return new self(
            fromAddress: $this->fromAddress,
            toAddress: $this->toAddress,
            packageData: $this->packageData,
            selectedRate: $this->selectedRate,
            customsItems: $scaledItems,
            labelFormat: $this->labelFormat,
            labelDpi: $this->labelDpi,
            specialServiceCodes: $this->specialServiceCodes,
            locationId: $this->locationId,
            clientId: $this->clientId,
            shipDate: $this->shipDate,
            specialServiceConfig: $this->specialServiceConfig,
            references: $this->references,
            packageId: $this->packageId,
        );
    }

    public static function fromPackageAndRate(
        Package $package,
        RateResponse $rate,
        string $labelFormat = 'pdf',
        ?int $labelDpi = null,
    ): self {
        $customsItems = [];

        // Load package items with relationships if not already loaded
        $package->loadMissing(['packageItems.product', 'packageItems.shipmentItem']);

        foreach ($package->packageItems as $packageItem) {
            $customsItems[] = CustomsItem::fromPackageItem($packageItem);
        }

        $package->loadMissing('location');
        $fromAddress = $package->location
            ? AddressData::fromLocation($package->location)
            : AddressData::fromConfig();

        $shipDate = app(ShipDateService::class)->getShipDate($rate->carrier, $package->location_id);

        $resolver = app(SpecialServiceResolver::class);
        $specialServiceCodes = $resolver->resolveForPackageAndRate($package, $rate);

        return new self(
            fromAddress: $fromAddress,
            toAddress: AddressData::fromShipment($package->shipment),
            packageData: PackageData::fromPackage($package),
            selectedRate: $rate,
            customsItems: $customsItems,
            labelFormat: $labelFormat,
            labelDpi: $labelDpi,
            specialServiceCodes: $specialServiceCodes,
            locationId: $package->location_id,
            clientId: $package->shipment->client_id,
            shipDate: $shipDate,
            specialServiceConfig: $resolver->configForPackage($package, $specialServiceCodes),
            references: app(LabelReferenceResolver::class)->forPackage($package),
            packageId: $package->id,
        );
    }
}
