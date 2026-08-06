<?php

/*
 | Shared foundation configuration. Cross-cutting defaults consumed by the shared kernel
 | (locales, money, pagination, ids). No business/domain values here.
 | (Branding/white-label defaults live in the BrandSetting singleton, not here.)
 */
return [
    // Public web (Next.js) origin — used e.g. by admin "preview" links back to the marketing site.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    // Locales
    'locales' => ['en', 'ar'],
    'default_locale' => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'rtl_locales' => ['ar'],

    // Timezone — application fallback when neither user nor organization sets one. IANA only.
    // Persisted timestamps remain UTC; this is the presentation/scheduling default.
    'default_timezone' => env('APP_TIMEZONE', 'UTC'),

    // Money
    'money' => [
        'default_currency' => env('DEFAULT_CURRENCY', 'SAR'),
        'minor_unit_scale' => 2,
    ],

    // Pagination
    'pagination' => [
        'default_per_page' => 15,
        'max_per_page' => 100,
    ],

    // Identifiers
    'ids' => [
        'public_id_version' => 7, // UUIDv7 (time-ordered)
    ],
];
