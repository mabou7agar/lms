<?php

/*
 | Commerce domain configuration. Payment provider is resolved via the PaymentGateway
 | abstraction — code never couples to a vendor SDK directly.
 */
return [
    'default_currency' => env('COMMERCE_DEFAULT_CURRENCY', 'SAR'),
    'supported_currencies' => ['SAR', 'USD', 'EGP'],

    'payment' => [
        'provider' => env('COMMERCE_PAYMENT_PROVIDER', 'fake'), // fake | stripe | paymob | moyasar | hyperpay | tap | aps
        // Fake webhook HMAC secret (local/test only). Real gateways use commerce.gateways.<provider>.webhook_secret.
        'webhook_secret' => env('COMMERCE_WEBHOOK_SECRET', 'whsec_fake'),
    ],

    'invoice' => [
        'prefix' => env('COMMERCE_INVOICE_PREFIX', 'INV'),
    ],

    'credit_note' => [
        'prefix' => env('COMMERCE_CREDIT_NOTE_PREFIX', 'CN'),
    ],

    'contract' => [
        // Order fulfillment requires acceptance of this contract template key.
        'required_key' => 'terms',
    ],

    /*
     | Server-authoritative tax. Default jurisdiction Saudi Arabia (15% VAT, seeded by
     | CommerceTaxSeeder). prices_include_tax toggles whether catalogue prices are tax-inclusive.
     */
    'tax' => [
        'default_country' => env('COMMERCE_TAX_DEFAULT_COUNTRY', 'SA'),
        'prices_include_tax' => (bool) env('COMMERCE_PRICES_INCLUDE_TAX', false),
    ],

    /*
     | Subscription lifecycle dunning windows (commerce:renew-subscriptions worker).
     */
    'subscriptions' => [
        'retry_days' => (int) env('COMMERCE_SUBSCRIPTION_RETRY_DAYS', 1),
        'grace_days' => (int) env('COMMERCE_SUBSCRIPTION_GRACE_DAYS', 3),
    ],

    /*
     | One-off order failed-payment recovery (commerce:retry-failed-payments worker).
     */
    'dunning' => [
        'max_attempts' => (int) env('COMMERCE_DUNNING_MAX_ATTEMPTS', 4),
        'window_hours' => (int) env('COMMERCE_DUNNING_WINDOW_HOURS', 72),
    ],

    /*
     | Per-provider gateway credentials + endpoints, resolved by GatewayManager via
     | config('commerce.gateways.<provider>'). Secrets come from the environment only.
     */
    'gateways' => [
        'fake' => [],

        'stripe' => [
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'base_url' => env('STRIPE_BASE_URL', 'https://api.stripe.com'),
        ],

        'paymob' => [
            'api_key' => env('PAYMOB_API_KEY'),
            'integration_id' => env('PAYMOB_INTEGRATION_ID'),
            'iframe_id' => env('PAYMOB_IFRAME_ID'),
            'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
            'webhook_secret' => env('PAYMOB_WEBHOOK_SECRET'),
            'base_url' => env('PAYMOB_BASE_URL', 'https://accept.paymob.com/api'),
        ],

        'moyasar' => [
            'secret_key' => env('MOYASAR_SECRET_KEY'),
            'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET'),
            'callback_url' => env('MOYASAR_CALLBACK_URL'),
            'base_url' => env('MOYASAR_BASE_URL', 'https://api.moyasar.com'),
        ],

        'hyperpay' => [
            'access_token' => env('HYPERPAY_ACCESS_TOKEN'),
            'entity_id' => env('HYPERPAY_ENTITY_ID'),
            'webhook_secret' => env('HYPERPAY_WEBHOOK_SECRET'),
            'hosted_url' => env('HYPERPAY_HOSTED_URL'),
            'base_url' => env('HYPERPAY_BASE_URL', 'https://eu-test.oppwa.com'),
        ],

        'tap' => [
            'secret_key' => env('TAP_SECRET_KEY'),
            'webhook_secret' => env('TAP_WEBHOOK_SECRET'),
            'redirect_url' => env('TAP_REDIRECT_URL'),
            'base_url' => env('TAP_BASE_URL', 'https://api.tap.company'),
        ],

        'aps' => [
            'access_code' => env('APS_ACCESS_CODE'),
            'merchant_identifier' => env('APS_MERCHANT_IDENTIFIER'),
            'request_phrase' => env('APS_REQUEST_PHRASE'),
            'response_phrase' => env('APS_RESPONSE_PHRASE'),
            'sha_type' => env('APS_SHA_TYPE', 'sha256'),
            'return_url' => env('APS_RETURN_URL'),
            'language' => env('APS_LANGUAGE', 'en'),
            'base_url' => env('APS_BASE_URL', 'https://checkout.payfort.com/FortAPI/paymentPage'),
            'api_url' => env('APS_API_URL', 'https://paymentservices.payfort.com/FortAPI/paymentApi'),
        ],
    ],
];
