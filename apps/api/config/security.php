<?php

/*
 | Security response headers + HSTS. Applied by App\Http\Middleware\SecurityHeaders on every
 | response. Kept in config so ops can tune per environment without code changes.
 */
return [

    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => env('SECURITY_FRAME_OPTIONS', 'DENY'),
        'Referrer-Policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'Permissions-Policy' => env('SECURITY_PERMISSIONS_POLICY', 'geolocation=(), microphone=(), camera=(), payment=()'),
    ],

    // JSON-only API surface: lock everything down by default.
    'csp' => env('SECURITY_CSP', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"),

    // HTML/asset surfaces served by this same app — the Filament admin panel (Blade + Livewire +
    // Alpine) and its Livewire endpoint. The API 'csp' above would block every stylesheet, script and
    // even login-form submission in a real browser, so these paths get a Filament-appropriate policy.
    'csp_web' => env('SECURITY_CSP_WEB', "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; media-src 'self' https:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"),

    // Request paths that receive 'csp_web' instead of the locked-down API 'csp'.
    'web_paths' => ['admin', 'admin/*', 'livewire/*'],

    // Config mirror of the trusted proxy/host allow-lists (bootstrap/app.php reads the raw env for
    // the framework; these give a cache-safe, testable view for ProductionConfigValidator).
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),
    'trusted_hosts' => env('APP_TRUSTED_HOSTS', ''),

    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS_ENABLED', true),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => (bool) env('SECURITY_HSTS_PRELOAD', true),
    ],

];
