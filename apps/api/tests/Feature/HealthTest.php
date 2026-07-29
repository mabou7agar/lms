<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Sprint 9 — liveness/readiness probes for LB/k8s. Liveness is dependency-free; readiness verifies
 * database, redis, queue and storage and never leaks connection details.
 */
it('exposes a dependency-free liveness endpoint', function () {
    $this->getJson('/api/v1/health/live')->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure(['status', 'service', 'version', 'time']);
});

it('keeps the legacy /health alias pointing at liveness', function () {
    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok');
});

it('reports readiness for database, redis, queue and storage', function () {
    $res = $this->getJson('/api/v1/health/ready');

    // All four dependencies are always reported (redis health is environment-dependent).
    expect($res->json('checks'))->toHaveKeys(['database', 'redis', 'queue', 'storage']);

    // The dependencies that are deterministic in the test runtime must report healthy — this is
    // what pins the new queue + storage probes.
    expect($res->json('checks.database.ok'))->toBeTrue()
        ->and($res->json('checks.queue.ok'))->toBeTrue()
        ->and($res->json('checks.storage.ok'))->toBeTrue();
});

it('never leaks connection details in the readiness payload', function () {
    $json = $this->getJson('/api/v1/health/ready')->json();

    // The payload is only status + per-dependency booleans + time — no host, credential or DSN.
    expect(array_keys($json))->toBe(['status', 'checks', 'time']);

    foreach ($json['checks'] as $check) {
        expect(array_keys($check))->toBe(['ok'])
            ->and($check['ok'])->toBeBool();
    }
});
