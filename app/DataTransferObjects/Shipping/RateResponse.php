<?php

namespace App\DataTransferObjects\Shipping;

use Carbon\Carbon;

readonly class RateResponse
{
    public function __construct(
        public string $carrier,
        public string $serviceCode,
        public string $serviceName,
        public float $price,
        public ?string $deliveryCommitment = null,
        public ?string $deliveryDate = null,
        public ?string $transitTime = null,
        public array $metadata = [],
        public bool $priceUnknown = false,
    ) {}

    /**
     * Convert to array format for Livewire serialization.
     *
     * @return array{carrier: string, serviceCode: string, serviceName: string, price: float, deliveryCommitment: ?string, deliveryDate: ?string, transitTime: ?string, metadata: array<string, mixed>, priceUnknown: bool}
     */
    public function toArray(): array
    {
        return [
            'carrier' => $this->carrier,
            'serviceCode' => $this->serviceCode,
            'serviceName' => $this->serviceName,
            'price' => $this->price,
            'deliveryCommitment' => $this->deliveryCommitment,
            'deliveryDate' => $this->deliveryDate,
            'transitTime' => $this->transitTime,
            'metadata' => $this->metadata,
            'priceUnknown' => $this->priceUnknown,
        ];
    }

    /**
     * Create a RateResponse from an array (lossless round-trip from toArray).
     *
     * @param  array{carrier: string, serviceCode: string, serviceName: string, price: float, deliveryCommitment: ?string, deliveryDate: ?string, transitTime: ?string, metadata?: array<string, mixed>, priceUnknown?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            carrier: $data['carrier'],
            serviceCode: $data['serviceCode'],
            serviceName: $data['serviceName'],
            price: (float) $data['price'],
            deliveryCommitment: $data['deliveryCommitment'] ?? null,
            deliveryDate: $data['deliveryDate'] ?? null,
            transitTime: $data['transitTime'] ?? null,
            metadata: $data['metadata'] ?? [],
            priceUnknown: (bool) ($data['priceUnknown'] ?? false),
        );
    }

    public function formLabel(): string
    {
        return "[{$this->carrier}] {$this->serviceName}";
    }

    public function parsedDeliveryDate(): ?Carbon
    {
        if (! $this->deliveryDate) {
            return null;
        }

        try {
            return Carbon::parse($this->deliveryDate);
        } catch (\Exception) {
            return null;
        }
    }

    public function formDescription(): string
    {
        // Carriers that expose no rate API (Shopify Shipping) price the label at
        // purchase time; showing "$0.00" would read as a free label.
        if ($this->priceUnknown) {
            $detail = $this->deliveryCommitment ?? $this->transitTime;

            return 'Price set at purchase'.($detail ? " — {$detail}" : '');
        }

        $price = number_format($this->price, 2);

        // Show actual delivery date when available
        $parsed = $this->parsedDeliveryDate();
        if ($parsed) {
            $formatted = $parsed->format('D, M j');

            return '$'.$price.' — Delivers '.$formatted;
        }

        // Fall back to commitment name or transit time
        $detail = $this->carrier === 'USPS'
            ? ($this->deliveryCommitment ?? '')
            : ($this->transitTime ?? '');

        return '$'.$price.($detail ? " — {$detail}" : '');
    }
}
