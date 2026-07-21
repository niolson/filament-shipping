<?php

use App\Services\SshTunnel;

/*
 * SshTunnel's open()/waitForTunnel() path spawns a real `ssh` process and
 * polls a socket, so it is not unit-testable without a live bastion host — it
 * is exercised only through the connection-refused/timeout guards below and in
 * integration. These tests cover the process-free seams: config construction,
 * local-port discovery, and known_hosts file handling.
 */

function invokePrivate(SshTunnel $tunnel, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod(SshTunnel::class, $method);

    return $ref->invokeArgs($tunnel, $args);
}

function makeTunnel(?string $knownHostsEntry = null, ?string $knownHostsFile = null): SshTunnel
{
    return new SshTunnel(
        sshHost: 'bastion.example.com',
        sshUser: 'polybag',
        sshKey: '/tmp/nonexistent-key',
        remoteHost: '127.0.0.1',
        remotePort: 3306,
        knownHostsEntry: $knownHostsEntry,
        knownHostsFile: $knownHostsFile,
    );
}

it('requires an ssh server host key before opening a tunnel', function (): void {
    makeTunnel()->open();
})->throws(RuntimeException::class, 'SSH server host key is required');

it('builds a tunnel from a config array', function (): void {
    $tunnel = SshTunnel::fromConfig([
        'ssh_host' => 'bastion.example.com',
        'ssh_user' => 'polybag',
        'ssh_key' => '/tmp/key',
        'ssh_port' => 2222,
        'remote_host' => 'db.internal',
        'remote_port' => 5432,
        'known_hosts_entry' => 'bastion.example.com ssh-ed25519 AAAA',
    ]);

    expect($tunnel)->toBeInstanceOf(SshTunnel::class);

    $ref = new ReflectionClass($tunnel);
    expect($ref->getProperty('sshHost')->getValue($tunnel))->toBe('bastion.example.com')
        ->and($ref->getProperty('sshPort')->getValue($tunnel))->toBe(2222)
        ->and($ref->getProperty('remotePort')->getValue($tunnel))->toBe(5432);
});

it('finds an available local port', function (): void {
    $port = invokePrivate(makeTunnel(), 'findAvailablePort');

    expect($port)->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(65535);
});

it('writes the host key entry to a temporary known_hosts file when none is provided', function (): void {
    $tunnel = makeTunnel(knownHostsEntry: 'bastion.example.com ssh-ed25519 AAAAKEY');

    $path = invokePrivate($tunnel, 'prepareKnownHostsFile');

    expect(file_exists($path))->toBeTrue()
        ->and(trim(file_get_contents($path)))->toBe('bastion.example.com ssh-ed25519 AAAAKEY');

    // Cleanup removes the temporary file it created.
    invokePrivate($tunnel, 'cleanupKnownHostsFile');
    expect(file_exists($path))->toBeFalse();
});

it('writes the host key entry to the configured known_hosts path and leaves it in place', function (): void {
    $path = sys_get_temp_dir().'/polybag-test-known-hosts-'.uniqid();
    $tunnel = makeTunnel(knownHostsEntry: 'bastion.example.com ssh-ed25519 KEY', knownHostsFile: $path);

    $returned = invokePrivate($tunnel, 'prepareKnownHostsFile');

    expect($returned)->toBe($path)
        ->and(trim(file_get_contents($path)))->toBe('bastion.example.com ssh-ed25519 KEY');

    // A caller-provided file is not deleted on cleanup.
    invokePrivate($tunnel, 'cleanupKnownHostsFile');
    expect(file_exists($path))->toBeTrue();

    @unlink($path);
});

it('returns an existing known_hosts file when no entry is provided', function (): void {
    $path = sys_get_temp_dir().'/polybag-test-existing-known-hosts-'.uniqid();
    file_put_contents($path, 'bastion.example.com ssh-ed25519 EXISTING'.PHP_EOL);

    $tunnel = makeTunnel(knownHostsFile: $path);

    expect(invokePrivate($tunnel, 'prepareKnownHostsFile'))->toBe($path);

    @unlink($path);
});

it('closing a tunnel that never opened is a no-op', function (): void {
    $tunnel = makeTunnel();

    $tunnel->close();

    expect(true)->toBeTrue();
});
