<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Pre-flight production config validation. Fails fast if required settings are missing or unsafe
 * for a production release. Read-only: touches no data, sends nothing.
 *
 * Usage: php artisan env:validate  (exit 0 = ok, 1 = problems found)
 */
class ValidateEnvironment extends Command
{
    protected $signature = 'env:validate {--production : Apply strict production checks}';

    protected $description = 'Validate environment + config for a HElbaron production release';

    public function handle(): int
    {
        $prod = $this->option('production') || app()->isProduction();
        $errors = [];
        $warn = [];

        // Always-required.
        foreach (['APP_KEY' => config('app.key'), 'DB connection' => config('database.default')] as $k => $v) {
            if (blank($v)) {
                $errors[] = "Missing {$k}";
            }
        }

        if ($prod) {
            if (config('app.debug')) {
                $errors[] = 'APP_DEBUG must be false in production';
            }
            if (config('app.env') !== 'production') {
                $warn[] = "APP_ENV is '".config('app.env')."' (expected 'production')";
            }
            if (! config('session.secure')) {
                $errors[] = 'SESSION_SECURE_COOKIE must be true over HTTPS';
            }
            if (in_array('*', (array) config('cors.allowed_origins'), true) || config('cors.allowed_origins') === []) {
                $errors[] = 'CORS allowed_origins must be an explicit allow-list (never *)';
            }
            if (config('logging.default') !== 'json' && ! in_array('json', (array) config('logging.channels.stack.channels', []), true)) {
                $warn[] = "LOG_CHANNEL is '".config('logging.default')."' (recommend 'json' in production)";
            }
            // Cache + queue must be shared/async backends in production. `array` cache is per-process
            // (breaks rate limiting, analytics caching and any multi-instance deploy); `sync` queue
            // runs every job inline in the request (defeats notifications, exports, fan-out).
            if (config('cache.default') === 'array') {
                $errors[] = 'CACHE_STORE is "array" (per-process, not shared) — use redis in production';
            }
            if (config('queue.default') === 'sync') {
                $errors[] = 'QUEUE_CONNECTION is "sync" (jobs run inline) — use redis in production';
            }
            // Trusted proxies: read the raw env because it is applied inline in bootstrap/app.php with
            // no config key. A warn, not a fail — "*" is valid behind a locked-down ALB, but should be
            // scoped to the balancer where the network allows it.
            $proxies = trim((string) env('TRUSTED_PROXIES', '*'));
            if ($proxies === '*' || $proxies === '') {
                $warn[] = 'TRUSTED_PROXIES trusts all proxies ("*") — scope it to your load balancer where possible';
            }
            // Provider secrets required only when the real provider is selected.
            if (config('commerce.payment.provider') === 'stripe' && blank(config('services.stripe.secret'))) {
                $errors[] = 'Stripe selected but STRIPE_SECRET is empty';
            }
            if (config('commerce.payment.provider') === 'stripe' && blank(config('services.stripe.webhook_secret'))) {
                $errors[] = 'Stripe selected but STRIPE_WEBHOOK_SECRET is empty';
            }
            if (config('learning.playback.provider') === 'mux' && blank(config('services.mux.signing_key'))) {
                $errors[] = 'Mux selected but MUX_SIGNING_KEY is empty';
            }
            foreach (['mail' => 'mailgun', 'sms' => 'twilio', 'push' => 'firebase'] as $ch => $real) {
                if (config("notifications.providers.{$ch}") === $real) {
                    $warn[] = "Notifications {$ch} uses real provider ({$real}) — confirm its secrets are set";
                }
            }
        }

        // Feature flags sanity.
        $flags = (array) config('features.flags', []);
        $this->line('Feature flags: '.($flags === [] ? 'none defined' : implode(', ', array_keys($flags))));

        foreach ($warn as $w) {
            $this->warn('WARN: '.$w);
        }
        foreach ($errors as $e) {
            $this->error('FAIL: '.$e);
        }

        if ($errors !== []) {
            $this->newLine();
            $this->error(count($errors).' problem(s) must be fixed before release.');

            return self::FAILURE;
        }

        $this->info('Environment OK'.($prod ? ' (production checks passed)' : '').'.');

        return self::SUCCESS;
    }
}
