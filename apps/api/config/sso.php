<?php

declare(strict_types=1);

/*
 | Social login / SSO configuration (Sprint 0.5).
 |
 | Mirrors the commerce gateway convention: providers are selected/validated from config, default to
 | off, and the `fake` provider is refused in production (ProductionConfigValidator) unless explicitly
 | allowed. Credentials come from env; only the concrete provider adapter reads them.
 |
 | The google/microsoft/apple/generic OIDC blocks are declared here now but disabled: their network +
 | JWKS-verification adapters land in the LOCAL-REQUIRED increment and plug into SocialAuthManager.
 */

return [
    // Master switch. When off, every social route fails closed (SSO_DISABLED).
    'enabled' => (bool) env('SSO_ENABLED', false),

    // Permit the fake provider in production (a deliberate non-auth preview environment only).
    'allow_fake_provider' => (bool) env('SSO_ALLOW_FAKE_PROVIDER', false),

    // Lifetime (seconds) of the signed CSRF `state`/`nonce` token minted at redirect time.
    'state_ttl' => (int) env('SSO_STATE_TTL', 600),

    // Where the provider sends the browser back to (the SPA route that posts code+state to /callback).
    'default_redirect_uri' => env('SSO_REDIRECT_URI'),

    'providers' => [

        // Deterministic, network-free provider — the local/testing seam (see FakeSocialProvider).
        'fake' => [
            'driver' => 'fake',
            'enabled' => (bool) env('SSO_FAKE_ENABLED', true),
        ],

        'google' => [
            'driver' => 'oidc',
            'enabled' => (bool) env('SSO_GOOGLE_ENABLED', false),
            'issuer' => 'https://accounts.google.com',
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
            'scopes' => ['openid', 'email', 'profile'],
        ],

        'microsoft' => [
            'driver' => 'oidc',
            'enabled' => (bool) env('SSO_MICROSOFT_ENABLED', false),
            // Tenant-scoped issuer (v2.0). 'common' allows any Azure AD tenant + personal accounts.
            'tenant' => env('MICROSOFT_TENANT', 'common'),
            'issuer' => env('MICROSOFT_ISSUER', 'https://login.microsoftonline.com/common/v2.0'),
            'client_id' => env('MICROSOFT_CLIENT_ID'),
            'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
            'authorization_endpoint' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_endpoint' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'jwks_uri' => 'https://login.microsoftonline.com/common/discovery/v2.0/keys',
            'scopes' => ['openid', 'email', 'profile'],
        ],

        'apple' => [
            'driver' => 'apple',
            'enabled' => (bool) env('SSO_APPLE_ENABLED', false),
            'issuer' => 'https://appleid.apple.com',
            'client_id' => env('APPLE_CLIENT_ID'), // Services ID
            'team_id' => env('APPLE_TEAM_ID'),
            'key_id' => env('APPLE_KEY_ID'),
            // Base64-encoded PEM of the Apple private key (.p8) used to sign the ES256 client secret.
            'private_key' => env('APPLE_PRIVATE_KEY'),
            'authorization_endpoint' => 'https://appleid.apple.com/auth/authorize',
            'token_endpoint' => 'https://appleid.apple.com/auth/token',
            'jwks_uri' => 'https://appleid.apple.com/auth/keys',
            'scopes' => ['openid', 'email', 'name'],
        ],
    ],
];
