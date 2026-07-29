<?php

/*
 | P2/W04 - Media ingestion configuration. No business rules here — only provider selection, upload
 | token lifetimes, and per-backend credentials. Concrete ingestion adapters are the ONLY code that
 | reads the credential blocks (secrets never leave the adapter). Signing config for playback still
 | lives in config/services.php (mux) + config/learning.php (playback.provider), unchanged.
 */
return [

    'ingestion' => [
        // fake (default) | mux | s3. `fake` forces every type onto the credential-free adapter so
        // local/dev/test never contact a vendor. Any other value routes streamed types (video/
        // audio) to Mux and stored types (document/file/image) to S3.
        'default' => env('MEDIA_INGESTION_PROVIDER', 'fake'),
    ],

    'upload' => [
        // How long a direct-upload slot / single-use finalize token stays valid.
        'token_ttl_seconds' => (int) env('MEDIA_UPLOAD_TTL', 3600),
    ],

    'playback' => [
        // Default TTL for signed playback URLs issued for Media-owned assets.
        'ttl_seconds' => (int) env('MEDIA_PLAYBACK_TTL', 600),
    ],

    // Mux ingestion (direct uploads + asset lifecycle webhooks). Signing keys for PLAYBACK remain
    // in services.mux; these are the API + webhook credentials used to create/verify uploads.
    'mux' => [
        'base_url' => env('MUX_BASE_URL', 'https://api.mux.com'),
        'token_id' => env('MUX_TOKEN_ID'),
        'token_secret' => env('MUX_TOKEN_SECRET'),
        'webhook_secret' => env('MUX_WEBHOOK_SECRET'),
        'cors_origin' => env('MUX_UPLOAD_CORS_ORIGIN', '*'),
        // Reject webhook timestamps older than this many seconds (replay protection).
        'webhook_tolerance' => (int) env('MUX_WEBHOOK_TOLERANCE', 300),
    ],

    // S3 (or S3-compatible) ingestion for stored files. Uses the framework 's3' filesystem disk for
    // presigned uploads; only the webhook secret is read directly here.
    's3' => [
        'disk' => env('MEDIA_S3_DISK', 's3'),
        'presign_ttl_seconds' => (int) env('MEDIA_S3_PRESIGN_TTL', 3600),
        'webhook_secret' => env('MEDIA_S3_WEBHOOK_SECRET'),
    ],

    // Shared HMAC secret for the credential-free fake adapter (tests + local only).
    'fake' => [
        'webhook_secret' => env('MEDIA_FAKE_WEBHOOK_SECRET', 'fake-media-secret'),
    ],
];
