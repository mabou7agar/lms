<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

        // Private media/objects. Delivered via short-lived signed URLs (CloudFront).
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            // CloudFront domain used to build signed delivery URLs.
            'cloudfront_url' => env('CLOUDFRONT_URL'),
        ],

        // Dev-only public media store (MEDIA_INGESTION_PROVIDER=local). Publicly readable objects under
        // storage/app/public/media, served at APP_URL/storage/media via the storage:link symlink below.
        // Never used in production, where media lives on S3/Mux.
        'media_local' => [
            'driver' => 'local',
            'root' => storage_path('app/public/media'),
            'url' => env('APP_URL', 'http://localhost:8000').'/storage/media',
            'visibility' => 'public',
            'serve' => true,
            'throw' => false,
        ],
    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
