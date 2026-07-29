<?php

namespace App\Contexts\Commerce\Providers;

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Events\ContractAccepted;
use App\Contexts\Commerce\Events\OrderPaid;
use App\Contexts\Commerce\Events\OrderRefunded;
use App\Contexts\Commerce\Listeners\FulfillOnContractAccepted;
use App\Contexts\Commerce\Listeners\FulfillOnOrderPaid;
use App\Contexts\Commerce\Listeners\RevokeEnrollmentsOnRefund;
use App\Contexts\Commerce\Models\Contract;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\Product;
use App\Contexts\Commerce\Payments\GatewayManager;
use App\Contexts\Commerce\Policies\ContractPolicy;
use App\Contexts\Commerce\Policies\OrderPolicy;
use App\Contexts\Commerce\Policies\ProductPolicy;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Wires the Commerce module: config, migrations, routes, policies, the PaymentGateway binding
 * (Fake default, never Stripe directly), and the fulfillment/refund listeners.
 */
class CommerceServiceProvider extends BaseDomainServiceProvider
{
    protected array $routeFiles = ['routes/commerce.php'];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        Order::class => OrderPolicy::class,
        Contract::class => ContractPolicy::class,
        Product::class => ProductPolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/commerce.php', 'commerce');

        // Payment code depends only on the abstraction; the concrete gateway comes from config.
        $this->app->bind(PaymentGateway::class, fn ($app) => $app->make(GatewayManager::class)->resolve());
    }

    protected function bootDomain(): void
    {
        $this->registerRateLimiters();

        // Fulfillment is gated on BOTH payment and contract acceptance.
        Event::listen(OrderPaid::class, FulfillOnOrderPaid::class);
        Event::listen(ContractAccepted::class, FulfillOnContractAccepted::class);
        Event::listen(OrderRefunded::class, RevokeEnrollmentsOnRefund::class);
    }

    private function registerRateLimiters(): void
    {
        // Checkout keyed by user (falls back to IP): bounds gateway calls + order creation.
        RateLimiter::for('commerce-checkout', fn (Request $r) => Limit::perMinute(10)
            ->by('checkout|'.($r->user()?->getAuthIdentifier() ?? $r->ip())));

        // Payment webhook is public and unauthenticated, so it can only be keyed by source IP.
        // The signature check is the real control; this is defence in depth against brute-force and
        // request floods. 60/min is generous — a payment provider sends events individually and
        // retries with backoff, so legitimate traffic stays well under it, while an attacker is
        // capped at 60 (unforgeable) signature attempts per minute per source.
        RateLimiter::for('commerce-webhook', fn (Request $r) => Limit::perMinute(60)
            ->by('payment-webhook|'.$r->ip()));
    }
}
