<?php

namespace App\DataTransferObjects\PostageSources;

use Illuminate\Support\Collection;

/**
 * Who to ask for this package's postage, and what could not be decided.
 *
 * The conflicts half is the reason this is a result object rather than a bare
 * collection. ADR-0002 decision 9 makes an unresolvable tie a configuration
 * error surfaced to the operator, never an arbitrary pick — so the carrier it
 * concerns leaves `candidates` and arrives here with a reason instead, in the
 * shape `ShippingRateService::getExclusions()` already renders.
 */
readonly class PostageSourceResolution
{
    /**
     * @param  Collection<int, PostageSourceCandidate>  $candidates  most specific first
     * @param  array<int, array{carrier: string, reason: string}>  $conflicts
     */
    public function __construct(
        public Collection $candidates,
        public array $conflicts = [],
    ) {}

    /**
     * The channel source bound to this package, of which there is at most one:
     * a purchase is keyed to an order that lives in exactly one account.
     */
    public function channel(): ?PostageSourceCandidate
    {
        return $this->candidates->first(
            fn (PostageSourceCandidate $candidate): bool => $candidate->isChannel()
        );
    }

    /**
     * @return Collection<int, PostageSourceCandidate>
     */
    public function forCarrier(string $carrier): Collection
    {
        return $this->candidates
            ->filter(fn (PostageSourceCandidate $candidate): bool => $candidate->carrier === $carrier)
            ->values();
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }
}
