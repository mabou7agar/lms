<?php

use Illuminate\Support\Str;

/*
 | Horizon supervisors. Dedicated queues keep notifications/exports from starving each other.
 | Retry/timeout are explicit; failed jobs land in failed_jobs and the app's own dead-letter
 | logic (e.g. notification deliveries) handles terminal states.
 */
return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug((string) env('APP_NAME', 'helbaron'), '_').'_horizon:'),

    'middleware' => ['web'],

    'waits' => ['redis:default' => 60],
    'trim' => [
        'recent' => 60, 'pending' => 60, 'completed' => 60,
        'recent_failed' => 10080, 'failed' => 10080, 'monitored' => 10080,
    ],

    'silenced' => [],
    'metrics' => ['trim_snapshots' => ['job' => 24, 'queue' => 24]],
    // Worker-restart safety: on `horizon:terminate` (deploys) the terminating master returns
    // immediately while its workers finish their in-flight job in the background, so a rolling
    // deploy is not blocked by a long-running job. Safe because after_commit + the atomic
    // idempotency already prevent partial/duplicate work.
    'fast_termination' => (bool) env('HORIZON_FAST_TERMINATION', true),
    'memory_limit' => (int) env('HORIZON_MEMORY_LIMIT', 128),

    /*
     | Supervisor `timeout` MUST stay below the connection `retry_after` (queue.php, 360s) so a
     | still-running job is never re-released to a second worker. Current headroom: default 60s,
     | notifications 30s, exports 300s — all < 360s.
     |
     | `maxTime` / `maxJobs` recycle each worker process periodically (time- and count-bounded) so a
     | slow memory leak or a stale DB/Redis connection can never accumulate across a long-lived
     | worker — the worker exits cleanly after finishing its current job and Horizon respawns it.
     */
    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => (int) env('HORIZON_DEFAULT_MAX_PROCESSES', 4),
            'minProcesses' => 1,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'tries' => (int) env('HORIZON_DEFAULT_TRIES', 3),
            'timeout' => 60,
            'maxTime' => (int) env('HORIZON_DEFAULT_MAX_TIME', 3600),
            'maxJobs' => (int) env('HORIZON_DEFAULT_MAX_JOBS', 1000),
            'nice' => 0,
        ],
        'supervisor-notifications' => [
            'connection' => 'redis',
            'queue' => ['notifications'],
            'balance' => 'auto',
            'maxProcesses' => (int) env('HORIZON_NOTIFICATIONS_MAX_PROCESSES', 6),
            'minProcesses' => 1,
            'tries' => (int) env('HORIZON_NOTIFICATIONS_TRIES', 3),
            'timeout' => 30,
            'maxTime' => (int) env('HORIZON_NOTIFICATIONS_MAX_TIME', 3600),
            'maxJobs' => (int) env('HORIZON_NOTIFICATIONS_MAX_JOBS', 2000),
        ],
        'supervisor-exports' => [
            'connection' => 'redis',
            'queue' => ['exports'],
            'balance' => 'auto',
            'maxProcesses' => (int) env('HORIZON_EXPORTS_MAX_PROCESSES', 2),
            'minProcesses' => 1,
            'tries' => (int) env('HORIZON_EXPORTS_TRIES', 2),
            'timeout' => 300,
            'maxTime' => (int) env('HORIZON_EXPORTS_MAX_TIME', 3600),
            'maxJobs' => (int) env('HORIZON_EXPORTS_MAX_JOBS', 500),
        ],
        // Outbound customer webhooks. Isolated so a slow/failing customer endpoint (each request can
        // take up to the 10s HTTP timeout) never starves notifications/exports. DeliverWebhookJob
        // orchestrates its own retries/backoff on the delivery row, so tries stays 1 here.
        'supervisor-webhooks' => [
            'connection' => 'redis',
            'queue' => ['webhooks'],
            'balance' => 'auto',
            'maxProcesses' => (int) env('HORIZON_WEBHOOKS_MAX_PROCESSES', 3),
            'minProcesses' => 1,
            'tries' => 1,
            'timeout' => 30,
            'maxTime' => (int) env('HORIZON_WEBHOOKS_MAX_TIME', 3600),
            'maxJobs' => (int) env('HORIZON_WEBHOOKS_MAX_JOBS', 1000),
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-default' => ['maxProcesses' => (int) env('HORIZON_DEFAULT_MAX_PROCESSES', 6)],
            'supervisor-notifications' => ['maxProcesses' => (int) env('HORIZON_NOTIFICATIONS_MAX_PROCESSES', 10)],
            'supervisor-exports' => ['maxProcesses' => (int) env('HORIZON_EXPORTS_MAX_PROCESSES', 3)],
            'supervisor-webhooks' => ['maxProcesses' => (int) env('HORIZON_WEBHOOKS_MAX_PROCESSES', 5)],
        ],
        'local' => [
            'supervisor-default' => ['maxProcesses' => 3],
            'supervisor-notifications' => ['maxProcesses' => 3],
            'supervisor-exports' => ['maxProcesses' => 1],
            'supervisor-webhooks' => ['maxProcesses' => 1],
        ],
    ],
];
