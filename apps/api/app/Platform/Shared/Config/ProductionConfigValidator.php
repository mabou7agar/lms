<?php

namespace App\Platform\Shared\Config;

/**
 * Validates that the runtime configuration is safe for a production deployment.
 *
 * `errors()` returns every problem (fatal + advisory-as-fatal for prod); `criticalErrors()` returns
 * the subset that must hard-fail a production web boot (see AppServiceProvider). Reads ONLY from
 * config() / env() so it is deterministic and unit-testable by setting config values. It never logs
 * or echoes any secret value — messages name the variable, never its contents.
 *
 * A `fake` payment gateway is rejected in production unless COMMERCE_ALLOW_FAKE_GATEWAY=true is set
 * explicitly (a deliberate, safe non-payment escape hatch, e.g. a content-only preview environment).
 */
class ProductionConfigValidator
{
    /** Cache/session/queue drivers that are unsafe (non-persistent or synchronous) in production. */
    private const EPHEMERAL_STORES = ['array'];

    /**
     * All production configuration problems. Empty array == valid.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        $e = $this->criticalErrors();

        // Advisory-but-blocking for production hygiene.
        if (in_array((string) config('session.driver'), ['file'], true)) {
            // file sessions work but do not survive horizontal scaling; prefer redis/database.
            $e[] = 'SESSION_DRIVER=file does not survive multi-node scaling — prefer redis or database.';
        }

        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            $e[] = "MAIL_MAILER={$mailer} does not deliver real email in production.";
        }
        if (trim((string) config('mail.from.address')) === '') {
            $e[] = 'MAIL_FROM_ADDRESS is not set.';
        }

        if ((string) config('logging.default') === 'single') {
            $e[] = 'A single-file log channel is a dev default — production should use a structured channel (stack/stderr/json) so logs are shippable and not verbose.';
        }

        if (trim((string) config('security.trusted_hosts', '')) === '') {
            $e[] = 'APP_TRUSTED_HOSTS is not set — the host allow-list is required behind a load balancer.';
        }

        return array_values(array_unique($e));
    }

    /**
     * The subset of problems that must prevent a production web process from serving traffic.
     *
     * @return list<string>
     */
    public function criticalErrors(): array
    {
        $e = [];

        if (trim((string) config('app.key')) === '') {
            $e[] = 'APP_KEY is not set.';
        }
        if ((string) config('app.env') !== 'production') {
            $e[] = 'APP_ENV must be "production" in a production deployment.';
        }
        if ((bool) config('app.debug') === true) {
            $e[] = 'APP_DEBUG must be false in production (true leaks stack traces and secrets).';
        }
        $url = (string) config('app.url');
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            $e[] = 'APP_URL must be set to an https:// URL in production.';
        }

        // Database.
        $default = (string) config('database.default');
        if ($default === 'sqlite') {
            $e[] = 'DB_CONNECTION=sqlite is not a production database.';
        }
        $db = (array) config("database.connections.{$default}", []);
        foreach (['host', 'database', 'username'] as $key) {
            if (trim((string) ($db[$key] ?? '')) === '') {
                $e[] = "Database connection '{$default}' is missing {$key}.";
            }
        }

        // Redis host.
        if (trim((string) config('database.redis.default.host', config('database.redis.host', ''))) === '') {
            $e[] = 'REDIS_HOST is not configured.';
        }

        // Queue must not be synchronous.
        if ((string) config('queue.default') === 'sync') {
            $e[] = 'QUEUE_CONNECTION=sync runs jobs inline — production must use redis (or another async driver).';
        }

        // Cache / session drivers must be persistent.
        if (in_array((string) config('cache.default'), self::EPHEMERAL_STORES, true)) {
            $e[] = 'CACHE_STORE=array is non-persistent — production must use redis/database.';
        }
        if (in_array((string) config('session.driver'), self::EPHEMERAL_STORES, true)) {
            $e[] = 'SESSION_DRIVER=array is non-persistent — production must use redis/database.';
        }
        if ((bool) config('session.secure', false) !== true) {
            $e[] = 'SESSION_SECURE_COOKIE must be true in production (cookies over HTTPS only).';
        }

        // Payments: reject the fake gateway unless explicitly permitted.
        $provider = (string) config('commerce.payment.provider');
        if ($provider === 'fake' && (bool) config('commerce.payment.allow_fake_gateway', false) !== true) {
            $e[] = 'COMMERCE_PAYMENT_PROVIDER=fake is not allowed in production (set COMMERCE_ALLOW_FAKE_GATEWAY=true only for a deliberate non-payment environment).';
        }
        $secret = trim((string) config('commerce.payment.webhook_secret'));
        if ($secret === '' || $secret === 'whsec_fake') {
            $e[] = 'COMMERCE_WEBHOOK_SECRET is unset or still the dev default — webhooks cannot be securely verified.';
        }

        // Media provider must not be the fake ingestion provider in production. (Reads the REAL key
        // media.ingestion.default that IngestionProviderManager consumes — the previous media.provider
        // key never existed, so this guard was a silent no-op.)
        if ((string) config('media.ingestion.default', '') === 'fake') {
            $e[] = 'MEDIA_INGESTION_PROVIDER=fake is a dev/test stub and must not run in production.';
        }

        // Notification transports must not be the fake stubs in production (they never actually deliver
        // mail/SMS/push). Allow an explicit escape hatch for a deliberate non-delivery environment.
        if ((bool) config('notifications.allow_fake_providers', false) !== true) {
            foreach (['mail', 'sms', 'push'] as $channel) {
                if ((string) config('notifications.providers.'.$channel) === 'fake') {
                    $e[] = 'NOTIFICATIONS '.$channel.' provider "fake" is a dev/test stub and must not run in production (set NOTIFICATIONS_ALLOW_FAKE=true only for a deliberate non-delivery environment).';
                }
            }
        }

        // SSO: the fake social provider accepts logins with no real IdP — refuse it in production
        // (when SSO is on) unless explicitly permitted for a deliberate non-auth environment.
        if ((bool) config('sso.enabled', false) === true
            && (bool) config('sso.providers.fake.enabled', false) === true
            && (bool) config('sso.allow_fake_provider', false) !== true) {
            $e[] = 'SSO fake provider is enabled in production (set SSO_ALLOW_FAKE_PROVIDER=true only for a deliberate non-auth environment).';
        }

        // AI must be an explicit production opt-in. The deterministic fake provider is useful for
        // tests and previews, but must never be presented to learners as a real model by accident.
        if ((bool) config('ai.enabled', false) === true
            && (string) config('ai.default_provider', 'fake') === 'fake'
            && (bool) config('ai.allow_fake', false) !== true) {
            $e[] = 'AI_PROVIDER=fake is not allowed in production (set AI_ALLOW_FAKE=true only for a deliberate preview environment).';
        }

        // E-invoicing: the fake provider marks invoices "cleared" without contacting the tax authority.
        // Refuse it in production (when e-invoicing is on) unless explicitly permitted.
        if ((bool) config('commerce.einvoicing.enabled', false) === true
            && (string) config('commerce.einvoicing.provider') === 'fake'
            && (bool) config('commerce.einvoicing.allow_fake_provider', false) !== true) {
            $e[] = 'COMMERCE_EINVOICING_PROVIDER=fake is not allowed in production (set COMMERCE_EINVOICING_ALLOW_FAKE=true only for a deliberate non-fiscal environment).';
        }

        // Trusted proxies must be explicit (fail-closed W07 default trusts nothing, defeating rate limits).
        if (trim((string) config('security.trusted_proxies', '')) === '') {
            $e[] = 'TRUSTED_PROXIES is not set — required behind a load balancer for correct client IPs and rate limiting.';
        }

        return array_values(array_unique($e));
    }
}
