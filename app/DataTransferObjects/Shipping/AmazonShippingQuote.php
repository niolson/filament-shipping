<?php

namespace App\DataTransferObjects\Shipping;

/**
 * One `getRates` reply, still in Amazon's own vocabulary.
 *
 * The token belongs to the reply rather than to any one rate — `purchaseShipment`
 * takes both, and neither is reconstructible from the carrier and service
 * (ADR-0002 decision 4). Keeping them together is what stops a rate being
 * carried around without the token that can spend it.
 *
 * `ineligibleRates` is here for the same reason it is worth reading at all: the
 * production run returned 102 of them across fourteen carriers, which is the
 * catalog. Their reason codes are `UNKNOWN` on every single entry, so what is
 * harvested from them is identity and nothing else.
 */
readonly class AmazonShippingQuote
{
    /**
     * @param  list<array<string, mixed>>  $rates
     * @param  list<array<string, mixed>>  $ineligibleRates
     */
    public function __construct(
        public string $requestToken,
        public array $rates = [],
        public array $ineligibleRates = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            requestToken: (string) ($payload['requestToken'] ?? ''),
            rates: array_values(array_filter($payload['rates'] ?? [], 'is_array')),
            ineligibleRates: array_values(array_filter($payload['ineligibleRates'] ?? [], 'is_array')),
        );
    }
}
