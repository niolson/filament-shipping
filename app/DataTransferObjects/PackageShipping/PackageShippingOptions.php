<?php

namespace App\DataTransferObjects\PackageShipping;

readonly class PackageShippingOptions
{
    /**
     * @param  array<int, array<string, mixed>>  $rateOptions
     * @param  array<int, string>  $rateOptionLabels
     * @param  array<int, string>  $rateOptionDescriptions
     * @param  array<int, array{carrier: string, reason: string}>  $exclusions
     * @param  array<int, array<string, mixed>>  $blindPurchaseOffers  Priceless offers, kept out of `$rateOptions` so nothing can rank them against a quote (ADR-0003 decision 6)
     */
    public function __construct(
        public array $rateOptions,
        public array $rateOptionLabels,
        public array $rateOptionDescriptions,
        public ?string $deliverByDate,
        public bool $allRatesLate,
        public array $exclusions = [],
        public ?int $selectedRateIndex = null,
        public ?string $blockingError = null,
        public array $blindPurchaseOffers = [],
    ) {}

    public static function blocked(string $message): self
    {
        return new self(
            rateOptions: [],
            rateOptionLabels: [],
            rateOptionDescriptions: [],
            deliverByDate: null,
            allRatesLate: false,
            blockingError: $message,
        );
    }
}
