<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\Package;
use App\Services\SpecialServiceResolver;
use Carbon\CarbonImmutable;

readonly class RateRequest
{
    /**
     * @param  array<PackageData>  $packages
     * @param  array<int, string>  $specialServiceCodes
     * @param  array<string, array<string, mixed>>  $specialServiceConfig  Per-code config values (e.g. declared_value amount)
     */
    public function __construct(
        public string $originPostalCode,
        public string $destinationPostalCode,
        public string $originCountry = 'US',
        public string $destinationCountry = 'US',
        public ?string $destinationCity = null,
        public ?string $destinationStateOrProvince = null,
        public ?bool $residential = null,
        public array $packages = [],
        public array $specialServiceCodes = [],
        public ?int $locationId = null,
        public ?int $clientId = null,
        public ?CarbonImmutable $shipDate = null,
        public array $specialServiceConfig = [],
        public ?string $originCity = null,
        public ?string $originStateOrProvince = null,
        public ?float $contentsValue = null,
    ) {}

    public static function fromPackage(Package $package): self
    {
        $shipment = $package->shipment;
        $origin = AddressData::fromConfig();

        if ($package->location) {
            $origin = AddressData::fromLocation($package->location);
        } elseif ($package->location_id) {
            $package->load('location');
            if ($package->location) {
                $origin = AddressData::fromLocation($package->location);
            }
        }

        $resolver = app(SpecialServiceResolver::class);
        $specialServiceCodes = $resolver->resolveForPackage($package);

        return new self(
            originPostalCode: $origin->postalCode ?? '',
            destinationPostalCode: $shipment->validated_postal_code ?? $shipment->postal_code,
            originCountry: $origin->country,
            destinationCountry: $shipment->validated_country ?? $shipment->country ?? 'US',
            destinationCity: $shipment->validated_city ?? $shipment->city,
            destinationStateOrProvince: $shipment->validated_state_or_province ?? $shipment->state_or_province,
            residential: $shipment->validated_residential ?? $shipment->residential,
            packages: [PackageData::fromPackage($package)],
            specialServiceCodes: $specialServiceCodes,
            locationId: $package->location_id,
            clientId: $package->shipment->client_id,
            specialServiceConfig: $resolver->configForPackage($package, $specialServiceCodes),
            originCity: $origin->city,
            originStateOrProvince: $origin->stateOrProvince,
            contentsValue: $shipment->value !== null ? (float) $shipment->value : null,
        );
    }

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
            originPostalCode: $this->originPostalCode,
            destinationPostalCode: $this->destinationPostalCode,
            originCountry: $this->originCountry,
            destinationCountry: $this->destinationCountry,
            destinationCity: $this->destinationCity,
            destinationStateOrProvince: $this->destinationStateOrProvince,
            residential: $this->residential,
            packages: $this->packages,
            specialServiceCodes: $codes,
            locationId: $this->locationId,
            clientId: $this->clientId,
            shipDate: $this->shipDate,
            specialServiceConfig: $this->specialServiceConfig,
            originCity: $this->originCity,
            originStateOrProvince: $this->originStateOrProvince,
            contentsValue: $this->contentsValue,
        );
    }

    public function withShipDate(CarbonImmutable $date): self
    {
        return new self(
            originPostalCode: $this->originPostalCode,
            destinationPostalCode: $this->destinationPostalCode,
            originCountry: $this->originCountry,
            destinationCountry: $this->destinationCountry,
            destinationCity: $this->destinationCity,
            destinationStateOrProvince: $this->destinationStateOrProvince,
            residential: $this->residential,
            packages: $this->packages,
            specialServiceCodes: $this->specialServiceCodes,
            locationId: $this->locationId,
            clientId: $this->clientId,
            shipDate: $date,
            specialServiceConfig: $this->specialServiceConfig,
            originCity: $this->originCity,
            originStateOrProvince: $this->originStateOrProvince,
            contentsValue: $this->contentsValue,
        );
    }
}
