<?php

namespace App\DataTransferObjects\Shipping;

/**
 * What a run of the inference ladder concluded about a package's service.
 *
 * A conclusive result names the service, the rung that produced it, and the
 * ruleset it was produced under — the three things
 * `Package::assertServiceEvidenceIsConsistent()` requires before an inferred
 * service may be written. An inconclusive one names only why it stopped, which is
 * what the coverage measurement in ADR-0003 is counted from.
 */
readonly class ServiceInference
{
    private function __construct(
        public ?string $service,
        public ?string $method,
        public ?string $rulesetVersion,
        public string $reason,
    ) {}

    public static function resolved(string $service, string $method, string $rulesetVersion): self
    {
        return new self($service, $method, $rulesetVersion, 'resolved');
    }

    /**
     * Nothing conclusive. The reason is diagnostic, never written to the package.
     */
    public static function inconclusive(string $reason): self
    {
        return new self(null, null, null, $reason);
    }

    public function isResolved(): bool
    {
        return $this->service !== null;
    }
}
