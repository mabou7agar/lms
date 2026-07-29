<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [
    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    'guard' => [],

    // H7 — token lifetime. `null` meant tokens NEVER expired: a stolen bearer stayed valid forever.
    // We now expire tokens after a configurable number of minutes. The default (30 days) closes the
    // hole while sitting comfortably above the 14-day web BFF session, so interactive users are not
    // logged out mid-session; operators can shorten it via SANCTUM_TOKEN_EXPIRATION_MINUTES, or set
    // it to a non-numeric/empty value only in environments that deliberately want non-expiring
    // tokens. Expired tokens are rejected at auth time and reaped by the scheduled
    // `sanctum:prune-expired` command (see routes/console.php). `Sanctum::actingAs()` in tests
    // bypasses this entirely, so test behavior is unchanged.
    'expiration' => is_numeric(env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 43200))
        ? (int) env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 43200)
        : null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
];
