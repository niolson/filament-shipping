<?php

namespace App\Enums;

/**
 * Outcome of one dependency check in App\Services\ReadinessProbe.
 */
enum DependencyStatus: string
{
    case Ok = 'ok';
    case Unreachable = 'unreachable';

    /**
     * The instance is not configured to use this dependency, so it was never
     * contacted. Distinct from Ok: reporting a Redis nobody talks to as
     * healthy would be a claim the probe never tested.
     */
    case Skipped = 'skipped';

    public function isHealthy(): bool
    {
        return $this !== self::Unreachable;
    }
}
