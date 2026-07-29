<?php

/**
 * Sprint 9 — the `env:validate` pre-flight gate. These pin the always-required checks and the new
 * production checks for a shared cache and an async queue.
 */
it('passes when the always-required settings are present', function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'database.default' => 'pgsql']);

    $this->artisan('env:validate')->assertExitCode(0);
});

it('fails production validation for a per-process cache and an inline queue', function () {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'database.default' => 'pgsql',
        'app.debug' => false,
        'cache.default' => 'array',
        'queue.default' => 'sync',
    ]);

    $this->artisan('env:validate --production')
        ->expectsOutputToContain('CACHE_STORE is "array"')
        ->expectsOutputToContain('QUEUE_CONNECTION is "sync"')
        ->assertExitCode(1);
});

it('accepts a production cache and queue on shared backends', function () {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'database.default' => 'pgsql',
        'cache.default' => 'redis',
        'queue.default' => 'redis',
    ]);

    // Redis cache + queue must not be the reason a production validation fails.
    $this->artisan('env:validate --production')
        ->doesntExpectOutputToContain('CACHE_STORE is "array"')
        ->doesntExpectOutputToContain('QUEUE_CONNECTION is "sync"');
});
