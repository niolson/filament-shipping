<?php

namespace App\Http\Controllers\Api;

use App\Enums\DependencyStatus;
use App\Http\Controllers\Controller;
use App\Services\ReadinessProbe;
use Illuminate\Http\JsonResponse;

/**
 * Readiness endpoint: 200 when this instance can reach its datastores, 503
 * when it cannot, so a monitor can act on the status code alone.
 *
 * The body names which dependency is unreachable, because that distinction is
 * the diagnosis — both at once points at the network path out of this instance,
 * one points at that datastore. It stays limited to those verdicts: no exception
 * messages, hostnames, driver names, or versions, which would describe the
 * deployment rather than its health.
 *
 * That trade assumes the route is reachable only by your monitoring, not from
 * the internet — restrict it at your reverse proxy. If it has to be public,
 * reduce the body to `status` alone.
 */
class HealthController extends Controller
{
    public function __invoke(ReadinessProbe $probe): JsonResponse
    {
        $checks = $probe->check();

        $ready = array_reduce(
            $checks,
            fn (bool $carry, DependencyStatus $status): bool => $carry && $status->isHealthy(),
            true,
        );

        return response()->json([
            'status' => $ready ? 'ok' : 'degraded',
            'checks' => array_map(fn (DependencyStatus $status): string => $status->value, $checks),
        ], $ready ? 200 : 503);
    }
}
