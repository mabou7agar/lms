<?php

/*
 | Queue config. Redis-backed via Horizon. after_commit=true so jobs never run against
 | uncommitted data. Failed jobs are persisted (database-uuids) for inspection/replay.
 |
 | WORKER-RESTART SAFETY INVARIANT (Sprint 5): retry_after is the seconds after which the queue
 | considers a reserved job abandoned and makes it available again. It MUST exceed the longest
 | worker `timeout` of any supervisor consuming this connection — otherwise a still-running job is
 | re-released and processed a second time by another worker (Laravel's canonical duplicate-run
 | bug). The longest Horizon timeout is the exports supervisor at 300s, so the default is 360s
 | (300 + a 60s safety buffer). If you add a supervisor with a larger timeout, raise this too.
 | This value is also the recovery window after an UNCLEAN worker death (OOM/SIGKILL): the job is
 | safely re-dispatched once, no sooner.
 */
return [
    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [
        'sync' => ['driver' => 'sync'],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 360),
            // Blocking pop: workers wait up to N seconds for a job instead of busy-polling Redis,
            // cutting idle Redis load. Kept opt-in (null = poll) so existing behavior is unchanged
            // unless an operator sets it. Must stay < retry_after.
            'block_for' => env('REDIS_QUEUE_BLOCK_FOR') !== null ? (int) env('REDIS_QUEUE_BLOCK_FOR') : null,
            'after_commit' => true,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 360),
            'after_commit' => true,
        ],
    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
        // Dead-letter retention window (hours) for queue:prune-failed. Read via config in
        // routes/console.php so it survives config:cache — a bare env() there returns null when the
        // config is cached, which would collapse --hours to 0 and prune all failure evidence.
        'prune_hours' => (int) env('QUEUE_FAILED_PRUNE_HOURS', 720),
    ],
];
