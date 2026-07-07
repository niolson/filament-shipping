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

        return new self(
            originPostalCode: $origin->postalCode ?? '',
            destinationPostalCode: $shipment->validated_postal_code ?? $shipment->postal_code,
            originCountry: $origin->country,
            destinationCountry: $shipment->validated_country ?? $shipment->country ?? 'US',
            destinationCity: $shipment->validated_city ?? $shipment->city,
            destinationStateOrProvince: $shipment->validated_state_or_province ?? $shipment->state_or_province,
            residential: $shipment->validated_residential ?? $shipment->residential,
            packages: [PackageData::fromPackage($package)],
            specialServiceCodes: app(SpecialServiceResolver::class)->resolveForPackage($package),
            locationId: $package->location_id,
            clientId: $package->shipment->client_id,
        );
    }

    public function hasSpecialService(string $code): bool
    {
        return in_array($code, $this->specialServiceCodes, true);
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
        );
    }
}
