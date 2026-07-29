<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Kubernetes/LB probes. Liveness answers "is the process up" (no dependencies). Readiness answers
 * "can it serve traffic" by checking Postgres, Redis, the queue backend, and the storage disk.
 * Never leaks connection details — each probe reports only a boolean.
 */
class HealthController extends Controller
{
    /** Liveness — cheap, dependency-free. */
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'helbaron-api',
            'version' => (string) config('app.version', '1.0.0-rc.1'),
            'time' => now()->toIso8601String(),
        ]);
    }

    /** Readiness — verifies critical dependencies; 503 if any is down. */
    public function ready(): JsonResponse
    {
        $checks = [
            // Each probe throws if the dependency is unreachable; the catch marks it down.
            'database' => $this->check(function (): bool {
                DB::connection()->getPdo();

                return true;
            }),
            'redis' => $this->check(function (): bool {
                Redis::connection()->ping();

                return true;
            }),
            // Reading the default queue's depth exercises the queue backend without enqueuing work.
            'queue' => $this->check(function (): bool {
                Queue::size();

                return true;
            }),
            // A read-only existence probe exercises the configured disk (local/S3) without writing.
            'storage' => $this->check(function (): bool {
                Storage::disk((string) config('filesystems.default', 'local'))->exists('.health-probe');

                return true;
            }),
        ];

        $ok = ! in_array(false, array_map(fn ($c) => $c['ok'], $checks), true);

        return response()->json([
            'status' => $ok ? 'ready' : 'degraded',
            'checks' => $checks,
            'time' => now()->toIso8601String(),
        ], $ok ? 200 : 503);
    }

    /**
     * @param  callable(): bool  $probe
     * @return array{ok: bool}
     */
    private function check(callable $probe): array
    {
        try {
            return ['ok' => (bool) $probe()];
        } catch (Throwable) {
            return ['ok' => false];
        }
    }
}
