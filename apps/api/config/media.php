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

    // P1 - Public (PUBLIC-visibility) media delivery. The resolver builds a STABLE, fingerprinted URL
    // keyed by the asset public id (never its storage key), so it is safe for long CDN caching. Point
    // base_url at a CDN host in production; it never carries a per-asset secret.
    'public' => [
        'base_url' => env('MEDIA_PUBLIC_BASE_URL'),
        'path_prefix' => env('MEDIA_PUBLIC_PATH_PREFIX', 'media/public'),
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

    /*
     | Phase A / D6 - Image processing pipeline. A deterministic, native ext-gd pipeline that derives
     | stripped, resized/cropped variants from an ALREADY-INGESTED image original. It never mutates the
     | original object; every variant is a NEW storage object written through the same filesystem disk
     | the original lives on. No vendor / no extra composer package — GD ships with the runtime.
     |
     | TENANCY NOTE (T1, later phase): none of these are tenant values; when org scoping lands, variant
     | rows inherit the tenant of their parent media_assets row (variant storage keys are namespaced by
     | the asset public id, so they are already partitioned per asset). The disk itself stays shared.
     */
    'images' => [
        // Disk that both the original and its derived variants live on. Defaults to the S3 ingestion
        // disk so variants sit beside the original object. Overridable for local/dev.
        'disk' => env('MEDIA_IMAGE_DISK', env('MEDIA_S3_DISK', 's3')),

        // Key prefix under which derived variants are written (never the original's key).
        'variant_prefix' => env('MEDIA_IMAGE_VARIANT_PREFIX', 'media/variants'),

        // Server-side accepted INPUT formats, verified by magic bytes (never by extension/mime header).
        'accepted_input_formats' => ['jpeg', 'png', 'gif', 'webp'],

        /*
         | Decompression-bomb + resource guards. Enforced from the image HEADER (getimagesizefromstring)
         | BEFORE any full decode, so a hostile tiny-file-huge-canvas image is rejected without ever
         | allocating its raster. Tune per deployment; defaults are generous for real photography.
         */
        'limits' => [
            'max_bytes' => (int) env('MEDIA_IMAGE_MAX_BYTES', 25 * 1024 * 1024), // 25 MB on-disk cap
            'max_width' => (int) env('MEDIA_IMAGE_MAX_WIDTH', 12000),
            'max_height' => (int) env('MEDIA_IMAGE_MAX_HEIGHT', 12000),
            // Total pixel budget (w*h). 40 MP ~= 8000x5000. The primary decompression-bomb defence.
            'max_pixels' => (int) env('MEDIA_IMAGE_MAX_PIXELS', 40_000_000),
        ],

        // Default per-format encode quality (0-100 for lossy; PNG uses png_level below).
        'quality' => [
            'webp' => (int) env('MEDIA_IMAGE_WEBP_QUALITY', 82),
            'jpeg' => (int) env('MEDIA_IMAGE_JPEG_QUALITY', 82),
            'avif' => (int) env('MEDIA_IMAGE_AVIF_QUALITY', 50),
        ],
        // Fixed PNG compression level (0-9) — pinned for deterministic output bytes.
        'png_level' => (int) env('MEDIA_IMAGE_PNG_LEVEL', 6),

        // The variant KEY within a set whose stored object is copied onto media_assets.thumbnail_ref.
        'thumbnail_key' => 'thumbnail',

        // Queue + retry policy for the async GenerateImageVariantsJob.
        'queue' => env('MEDIA_IMAGE_QUEUE', 'default'),
        'retry' => [
            'max_attempts' => (int) env('MEDIA_IMAGE_MAX_ATTEMPTS', 3),
            'backoff_seconds' => [10, 30, 120],
        ],

        // Map an asset's upload PURPOSE to a default variant SET when a surface is not passed explicitly.
        'purpose_surface' => [
            'lesson_image' => 'default',
        ],

        /*
         | Named variant SETS per surface (dimensions + formats). Each entry is a variant keyed by name:
         |   width/height : target box in px
         |   mode         : 'fit'   -> contain within box, aspect kept, NEVER upscaled (max-dim clamp)
         |                   'cover' -> scale to fill then centre-crop to EXACT width x height (thumbs)
         |   format       : webp (default) | jpeg | png | avif (avif emitted only if GD supports it)
         |   quality      : optional per-variant override of images.quality[format]
         | 'webp' is always the primary derived format; add an 'avif' twin only where worthwhile — it is
         | silently skipped (and reported) on runtimes whose GD lacks AVIF.
         */
        'variant_sets' => [
            // Fallback set for any image without a dedicated surface (e.g. lesson images).
            'default' => [
                'thumbnail' => ['width' => 320, 'height' => 180, 'mode' => 'cover', 'format' => 'webp'],
                'small' => ['width' => 640, 'height' => 640, 'mode' => 'fit', 'format' => 'webp'],
                'medium' => ['width' => 1280, 'height' => 1280, 'mode' => 'fit', 'format' => 'webp'],
                'large' => ['width' => 1920, 'height' => 1920, 'mode' => 'fit', 'format' => 'webp'],
            ],

            // Course card / hero thumbnail (16:9).
            'course_thumbnail' => [
                'thumbnail' => ['width' => 320, 'height' => 180, 'mode' => 'cover', 'format' => 'webp'],
                'small' => ['width' => 640, 'height' => 360, 'mode' => 'cover', 'format' => 'webp'],
                'medium' => ['width' => 1280, 'height' => 720, 'mode' => 'cover', 'format' => 'webp'],
                'large' => ['width' => 1920, 'height' => 1080, 'mode' => 'cover', 'format' => 'webp'],
            ],

            // Free-form media gallery — keep aspect, cap the long edge.
            'gallery' => [
                'thumbnail' => ['width' => 400, 'height' => 400, 'mode' => 'cover', 'format' => 'webp'],
                'medium' => ['width' => 1024, 'height' => 1024, 'mode' => 'fit', 'format' => 'webp'],
                'large' => ['width' => 2048, 'height' => 2048, 'mode' => 'fit', 'format' => 'webp'],
            ],

            // Category tile (square).
            'category' => [
                'thumbnail' => ['width' => 160, 'height' => 160, 'mode' => 'cover', 'format' => 'webp'],
                'medium' => ['width' => 480, 'height' => 480, 'mode' => 'cover', 'format' => 'webp'],
            ],

            // Instructor avatar (square).
            'instructor_avatar' => [
                'thumbnail' => ['width' => 96, 'height' => 96, 'mode' => 'cover', 'format' => 'webp'],
                'small' => ['width' => 192, 'height' => 192, 'mode' => 'cover', 'format' => 'webp'],
                'medium' => ['width' => 512, 'height' => 512, 'mode' => 'cover', 'format' => 'webp'],
            ],

            // Instructor cover / banner (wide).
            'instructor_cover' => [
                'thumbnail' => ['width' => 480, 'height' => 160, 'mode' => 'cover', 'format' => 'webp'],
                'medium' => ['width' => 1200, 'height' => 400, 'mode' => 'cover', 'format' => 'webp'],
                'large' => ['width' => 2400, 'height' => 800, 'mode' => 'cover', 'format' => 'webp'],
            ],

            // Certificate logo — transparency preserved, so PNG output (no lossy webp default here).
            'certificate_logo' => [
                'thumbnail' => ['width' => 128, 'height' => 128, 'mode' => 'fit', 'format' => 'png'],
                'medium' => ['width' => 512, 'height' => 512, 'mode' => 'fit', 'format' => 'png'],
            ],

            // Certificate background (print-ish, wide, flattened jpeg).
            'certificate_background' => [
                'medium' => ['width' => 1240, 'height' => 1754, 'mode' => 'cover', 'format' => 'jpeg'],
                'large' => ['width' => 2480, 'height' => 3508, 'mode' => 'cover', 'format' => 'jpeg'],
            ],

            // Certificate signature — small, transparent PNG.
            'certificate_signature' => [
                'small' => ['width' => 300, 'height' => 120, 'mode' => 'fit', 'format' => 'png'],
            ],

            // CMS / homepage hero.
            'cms_homepage' => [
                'thumbnail' => ['width' => 400, 'height' => 225, 'mode' => 'cover', 'format' => 'webp'],
                'medium' => ['width' => 1280, 'height' => 720, 'mode' => 'cover', 'format' => 'webp'],
                'large' => ['width' => 1920, 'height' => 1080, 'mode' => 'cover', 'format' => 'webp'],
            ],
        ],
    ],
];
