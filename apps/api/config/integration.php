<?php

declare(strict_types=1);

/*
 | Outbound Integration (customer webhook) configuration.
 |
 | Delivery attempts, exponential backoff, the consecutive-failure threshold that auto-disables a
 | flapping endpoint, and the SSRF/transport policy (HTTPS requirement). Everything the delivery job
 | and URL guard read is here — nothing is hardcoded in the job.
 */

return [
    'delivery' => [
        // Dedicated Horizon queue (see config/horizon.php supervisor-webhooks).
        'queue' => env('INTEGRATION_WEBHOOK_QUEUE', 'webhooks'),

        // Total delivery attempts (initial + retries) before a delivery is marked permanently failed.
        'max_attempts' => (int) env('INTEGRATION_WEBHOOK_MAX_ATTEMPTS', 5),

        // Per-request HTTP timeout (seconds).
        'timeout' => (int) env('INTEGRATION_WEBHOOK_TIMEOUT', 10),

        // Exponential backoff (seconds) indexed by prior-attempt count; the last value repeats.
        'backoff' => [10, 30, 120, 300, 900],
    ],

    'endpoint' => [
        // Consecutive failed deliveries before an endpoint is auto-disabled (disabled_at stamped).
        'failure_disable_threshold' => (int) env('INTEGRATION_WEBHOOK_FAILURE_THRESHOLD', 10),
    ],

    'security' => [
        // Require HTTPS for customer endpoints (transport confidentiality + SSRF hardening).
        // MUST stay true in production; overridable only for local/dev experimentation.
        'require_https' => (bool) env('INTEGRATION_WEBHOOK_REQUIRE_HTTPS', true),
    ],
];
