<?php

/*
 | Notifications configuration. Consumer domain: reacts to producer events and delivers through the
 | channel/provider abstractions. Providers default to 'fake' so local/test never send for real; a
 | channel with a fake/unconfigured provider is reported truthfully (Skipped/Failed), never Sent.
 | All delivery is queued.
 */
return [
    'locale' => [
        'default' => env('APP_LOCALE', 'en'),
        'fallback' => env('APP_FALLBACK_LOCALE', 'en'),
    ],
    'retry' => [
        'max_attempts' => (int) env('NOTIFICATIONS_MAX_ATTEMPTS', 3),
        'backoff_seconds' => [10, 60, 300],
    ],
    'rate_limit' => [
        'per_minute' => (int) env('NOTIFICATIONS_RATE_PER_MINUTE', 30),
        // How long a rate-limited delivery waits before a fresh attempt. The deferral re-dispatches
        // the job and consumes neither a delivery attempt nor a job try, so rate limiting can never
        // dead-letter a message that was never actually attempted.
        'retry_after_seconds' => (int) env('NOTIFICATIONS_RATE_RETRY_AFTER', 30),
    ],
    // Real provider selection per channel. Defaults to 'fake' so local/test never send.
    'providers' => [
        'mail' => env('NOTIFICATIONS_MAIL_PROVIDER', 'fake'),   // fake | mailgun
        'sms' => env('NOTIFICATIONS_SMS_PROVIDER', 'fake'),     // fake | twilio
        'push' => env('NOTIFICATIONS_PUSH_PROVIDER', 'fake'),   // fake | firebase
    ],
    // Per-channel enable toggles — independently configurable (H6). A disabled channel is recorded
    // as Skipped (Disabled), never Sent. In-app is always on (the notification row is the delivery).
    // Webhooks is off and stays off: registered for contract completeness, transport deferred to
    // ADR-16. Enablement lives here rather than in the platform feature-flag service to keep the
    // Notifications context free of a dependency on the Features module (Deptrac boundary).
    'channels' => [
        'email' => ['enabled' => env('NOTIFICATIONS_CHANNEL_EMAIL', true)],
        'sms' => ['enabled' => env('NOTIFICATIONS_CHANNEL_SMS', true)],
        'whatsapp' => ['enabled' => env('NOTIFICATIONS_CHANNEL_WHATSAPP', true)],
        'push' => ['enabled' => env('NOTIFICATIONS_CHANNEL_PUSH', true)],
        'webhooks' => ['enabled' => false],
    ],

    'queue' => env('NOTIFICATIONS_QUEUE', 'notifications'),
    'default_channels' => ['in_app'],

    // Marketing engine (campaigns / drip / automation). Quiet hours apply to the MARKETING category
    // ONLY: a marketing message due inside [start, end) in the recipient's timezone is deferred to the
    // window end, never dropped. Transactional/critical messages ignore this entirely. A per-user
    // quiet-hours preference (user_notification_settings) overrides these defaults for that user.
    'marketing' => [
        'quiet_hours' => [
            'enabled' => (bool) env('MARKETING_QUIET_HOURS_ENABLED', true),
            'start' => env('MARKETING_QUIET_HOURS_START', '21:00'),
            'end' => env('MARKETING_QUIET_HOURS_END', '08:00'),
        ],
    ],
    'digest' => [
        'enabled' => true,
    ],

    // H4 — async fan-out. A large recipient set is split into chunks of this size, and each chunk
    // is a queued, retry-safe job dispatched as one Bus batch, so a 10k-learner announcement never
    // runs in the HTTP request. Tune down for tighter per-job bounds, up for fewer jobs.
    'fanout' => [
        'chunk_size' => max(1, (int) env('NOTIFICATIONS_FANOUT_CHUNK', 500)),
    ],
];
