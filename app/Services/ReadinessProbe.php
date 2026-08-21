<?php

namespace App\Services;

use App\Enums\DependencyStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PDO;
use Throwable;

/**
 * Confirms that this instance can actually reach the datastores it serves
 * requests from.
 *
 * This backs the readiness endpoint, which is deliberately separate from the
 * `/up` liveness route. `/up` is what the container healthcheck polls, so it
 * must not fail on a datastore blip: Docker would mark the container unhealthy
 * and restart it, turning an outage into a restart loop.
 */
class ReadinessProbe
{
    /**
     * Seconds a single dependency check may spend connecting.
     *
     * Kept short so an unreachable datastore fails the probe quickly instead of
     * occupying a PHP worker until the default socket timeout expires. Also
     * used by the `readiness` Redis connection in `config/database.php`.
     *
     * Not the whole budget: Laravel's connector retries once on what it reads
     * as a lost connection, so a host that drops packets rather than refusing
     * them costs twice this on the database check — measured at 6s, plus 3s for
     * Redis, since both are always checked. A refused connection (the container
     * being down, rather than the network path being gone) returns immediately.
     */
    public const TIMEOUT_SECONDS = 3;

    /**
     * Name of the short-timeout connections the probe uses, in both
     * `database.connections` and `database.redis`.
     */
    private const PROBE_CONNECTION = 'readiness';

    /**
     * Both dependencies are checked even when the first one fails.
     *
     * Which of them are unreachable is the diagnosis. Losing both at once
     * generally means the network path out of this instance is gone; losing one
     * points at that datastore. Short-circuiting on the first failure would
     * collapse those into a single indistinguishable alert.
     *
     * @return array{database: DependencyStatus, redis: DependencyStatus}
     */
    public function check(): array
    {
        return [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ];
    }

    /**
     * Runs a trivial query over a short-timeout clone of the default
     * connection.
     *
     * A clone rather than the default connection so the tightened timeout
     * applies to this check alone. The query goes straight to the PDO rather
     * than through `Connection::select()`, which reconnects and retries on a
     * failure — doubling again the time an unreachable host holds the worker,
     * to no benefit for a check whose answer is "reachable or not".
     */
    private function checkDatabase(): DependencyStatus
    {
        $default = (string) config('database.default');
        $config = config('database.connections.'.$default);

        if (! is_array($config)) {
            return DependencyStatus::Unreachable;
        }

        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        $config['options'] = $options + [PDO::ATTR_TIMEOUT => self::TIMEOUT_SECONDS];

        config(['database.connections.'.self::PROBE_CONNECTION => $config]);

        try {
            DB::connection(self::PROBE_CONNECTION)->getPdo()->query('select 1');

            return DependencyStatus::Ok;
        } catch (Throwable) {
            return DependencyStatus::Unreachable;
        } finally {
            DB::purge(self::PROBE_CONNECTION);
        }
    }

    private function checkRedis(): DependencyStatus
    {
        if (! $this->usesRedis()) {
            return DependencyStatus::Skipped;
        }

        try {
            Redis::connection(self::PROBE_CONNECTION)->ping();

            return DependencyStatus::Ok;
        } catch (Throwable) {
            return DependencyStatus::Unreachable;
        }
    }

    /**
     * Whether this instance is configured to depend on Redis at all.
     *
     * The supported deployments put sessions, cache, and queues on Redis, but
     * nothing forces that — a self-hosted install can run them off other
     * drivers. Reporting such an instance degraded because a Redis it never
     * talks to is absent would be a false alarm, so the probe checks Redis only
     * when something is actually pointed at it.
     */
    private function usesRedis(): bool
    {
        $drivers = [
            config('session.driver'),
            config('cache.stores.'.(string) config('cache.default').'.driver'),
            config('queue.connections.'.(string) config('queue.default').'.driver'),
            config('broadcasting.connections.'.(string) config('broadcasting.default').'.driver'),
        ];

        return in_array('redis', $drivers, true);
    }
}
