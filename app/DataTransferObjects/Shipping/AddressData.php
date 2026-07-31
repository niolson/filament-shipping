<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\Location;
use App\Models\Shipment;
use App\Services\PhoneParserService;

readonly class AddressData
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $streetAddress,
        public string $city,
        public ?string $stateOrProvince,
        public ?string $postalCode,
        public string $country = 'US',
        public ?string $streetAddress2 = null,
        public ?string $company = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $phoneExtension = null,
    ) {}

    public static function fromShipment(Shipment $shipment): self
    {
        return new self(
            firstName: $shipment->first_name ?? '',
            lastName: $shipment->last_name ?? '',
            streetAddress: $shipment->validated_address1 ?? $shipment->address1,
            streetAddress2: $shipment->validated_address2 ?? $shipment->address2,
            city: $shipment->validated_city ?? $shipment->city,
            stateOrProvince: $shipment->validated_state_or_province ?? $shipment->state_or_province,
            postalCode: $shipment->validated_postal_code ?? $shipment->postal_code,
            country: $shipment->validated_country ?? $shipment->country ?? 'US',
            company: $shipment->validated_company ?? $shipment->company,
            phone: PhoneParserService::nationalDigits($shipment->phone_e164, $shipment->validated_country ?? $shipment->country ?? 'US'),
            email: $shipment->email,
            phoneExtension: $shipment->phone_extension,
        );
    }

    public static function fromLocation(Location $location): self
    {
        return new self(
            firstName: $location->first_name,
            lastName: $location->last_name,
            streetAddress: $location->address1,
            streetAddress2: $location->address2,
            city: $location->city,
            stateOrProvince: $location->state_or_province,
            postalCode: $location->postal_code,
            country: $location->country,
            company: $location->company,
            phone: PhoneParserService::nationalDigits($location->phone_e164, $location->country),
        );
    }

    public static function fromConfig(): self
    {
        $location = Location::getDefault();

        if (! $location) {
            throw new \RuntimeException('No default location configured. Go to Settings > Locations and set a default location.');
        }

        return self::fromLocation($location);
    }

    /**
     * Overseas military and diplomatic post office subdivisions.
     *
     * @var array<int, string>
     */
    private const MILITARY_SUBDIVISIONS = ['AA', 'AE', 'AP'];

    /**
     * City names identifying the same destinations, used when a subdivision
     * arrives unnormalized.
     *
     * @var array<int, string>
     */
    private const MILITARY_CITIES = ['APO', 'FPO', 'DPO'];

    /**
     * Whether this is an overseas military or diplomatic post office address.
     */
    public function isMilitary(): bool
    {
        if ($this->country !== 'US') {
            return false;
        }

        return in_array(strtoupper(trim((string) $this->stateOrProvince)), self::MILITARY_SUBDIVISIONS, true)
            || in_array(strtoupper(trim($this->city)), self::MILITARY_CITIES, true);
    }

    /**
     * Whether a shipment to this address carries a customs declaration.
     *
     * Military and diplomatic post offices cross a customs boundary despite
     * being domestic addresses, so "customs applies" is not the same question
     * as "the country is not US". Anything reasoning about customs data should
     * ask this rather than comparing the country itself, or the two answers
     * drift apart — which is how customs items reached the carrier without
     * passing through weight reconciliation first.
     */
    public function requiresCustomsDeclaration(): bool
    {
        return $this->country !== 'US' || $this->isMilitary();
    }
}
