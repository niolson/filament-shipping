<?php

use Illuminate\Support\Facades\Redis;

/**
 * `/up` is liveness only and `/api/health` is readiness. The separation is the
 * point: a container healthcheck polling a route that fails on a datastore blip
 * would restart the container mid-outage.
 */

/**
 * Repoints the default connection's config at a port nothing listens on.
 *
 * The config is rewritten rather than `database.default` swapped: the probe
 * builds its connection from this config on every request, while the live
 * connection RefreshDatabase holds a transaction open on keeps the PDO it was
 * created with — so the probe fails and the test still rolls back.
 */
function pointDatabaseAtUnreachableHost(): void
{
    config(['database.connections.'.config('database.default') => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'polybag',
        'username' => 'polybag',
        'password' => 'polybag',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]]);
}

/**
 * Puts the instance on Redis and points the probe connection at a port nothing
 * listens on. The Redis manager snapshots `database.redis` when it is first
 * resolved, so the existing instance has to go with the config change.
 */
function pointRedisAtUnreachableHost(): void
{
    config([
        'session.driver' => 'redis',
        'database.redis.readiness.host' => '127.0.0.1',
        'database.redis.readiness.port' => 1,
    ]);

    app()->forgetInstance('redis');
    Redis::clearResolvedInstances();
}

it('returns a generic status payload for /up', function (): void {
    $this->get('/up')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
        ]);
});

it('does not expose internal dependency names on /up', function (): void {
    $this->get('/up')
        ->assertOk()
        ->assertJsonMissingPath('db')
        ->assertJsonMissingPath('redis');
});

it('keeps /up healthy when the database is unreachable', function (): void {
    pointDatabaseAtUnreachableHost();

    $this->get('/up')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
        ]);
});

it('reports ready when the datastores are reachable', function (): void {
    $this->get('/api/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'checks' => ['database' => 'ok', 'redis' => 'skipped'],
        ]);
});

it('names the database when only the database is unreachable', function (): void {
    pointDatabaseAtUnreachableHost();

    $this->get('/api/health')
        ->assertStatus(503)
        ->assertExactJson([
            'status' => 'degraded',
            'checks' => ['database' => 'unreachable', 'redis' => 'skipped'],
        ]);
});

it('names redis when only redis is unreachable', function (): void {
    pointRedisAtUnreachableHost();

    $this->get('/api/health')
        ->assertStatus(503)
        ->assertExactJson([
            'status' => 'degraded',
            'checks' => ['database' => 'ok', 'redis' => 'unreachable'],
        ]);
});

/**
 * The case worth telling apart from the two above: when the network path out of
 * this instance is gone, every datastore goes at once. The probe must not
 * short-circuit on the first failure, or that is indistinguishable from a
 * database-only outage.
 */
it('names both when every datastore is unreachable', function (): void {
    pointDatabaseAtUnreachableHost();
    pointRedisAtUnreachableHost();

    $this->get('/api/health')
        ->assertStatus(503)
        ->assertExactJson([
            'status' => 'degraded',
            'checks' => ['database' => 'unreachable', 'redis' => 'unreachable'],
        ]);
});

it('reports redis skipped, not ok, when nothing is configured to use it', function (): void {
    config([
        'session.driver' => 'array',
        'cache.default' => 'array',
        'queue.default' => 'sync',
        'broadcasting.default' => 'null',
        'database.redis.readiness.host' => '127.0.0.1',
        'database.redis.readiness.port' => 1,
    ]);

    $this->get('/api/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'checks' => ['database' => 'ok', 'redis' => 'skipped'],
        ]);
});

it('never reveals configuration or failure detail on the readiness endpoint', function (string $scenario): void {
    if ($scenario === 'database') {
        pointDatabaseAtUnreachableHost();
    }

    if ($scenario === 'redis') {
        pointRedisAtUnreachableHost();
    }

    $body = (string) $this->get('/api/health')->getContent();
    $payload = json_decode($body, true);

    expect(array_keys($payload))->toBe(['status', 'checks']);
    expect(array_keys($payload['checks']))->toBe(['database', 'redis']);
    expect($payload['checks'])->each->toBeIn(['ok', 'unreachable', 'skipped']);

    // Which dependency failed is deliberate. Anything describing the
    // deployment — its driver, where it lives, or why the connection died —
    // is not.
    foreach (['fake_carriers', 'mysql', 'sqlite', '127.0.0.1', 'SQLSTATE', 'Connection refused', 'polybag', 'phpredis'] as $leak) {
        expect($body)->not->toContain($leak);
    }
})->with(['healthy', 'database', 'redis']);
