<?php

namespace App\Contexts\Commerce\Providers;

use App\Contexts\Commerce\Adapters\CompanyEntitlementAdapter;
use App\Contexts\Commerce\Adapters\EntitlementAdapter;
use App\Contexts\Commerce\Adapters\PurchaseSummaryAdapter;
use App\Contexts\Commerce\Console\Commands\RenewDueSubscriptionsCommand;
use App\Contexts\Commerce\Console\Commands\RetryFailedPaymentsCommand;
use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Contracts\TaxCalculator;
use App\Contexts\Commerce\Events\ContractAccepted;
use App\Contexts\Commerce\Events\OrderPaid;
use App\Contexts\Commerce\Events\OrderRefunded;
use App\Contexts\Commerce\Listeners\FulfillOnContractAccepted;
use App\Contexts\Commerce\Listeners\FulfillOnOrderPaid;
use App\Contexts\Commerce\Listeners\IssueCreditNoteOnRefund;
use App\Contexts\Commerce\Listeners\PopulateInvoiceLinesOnOrderPaid;
use App\Contexts\Commerce\Listeners\ReconcileCouponRedemptionOnOrderPaid;
use App\Contexts\Commerce\Listeners\RevokeEnrollmentsOnRefund;
use App\Contexts\Commerce\Models\Contract;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\Product;
use App\Contexts\Commerce\Payments\GatewayManager;
use App\Contexts\Commerce\Policies\ContractPolicy;
use App\Contexts\Commerce\Policies\OrderPolicy;
use App\Contexts\Commerce\Policies\ProductPolicy;
use App\Contexts\Commerce\Support\OrganizationSubscriptionExposureAdapter;
use App\Contexts\Commerce\Tax\Services\TaxService;
use App\Platform\Shared\Commerce\Contracts\CompanyEntitlementPort;
use App\Platform\Shared\Commerce\Contracts\EntitlementPort;
use App\Platform\Shared\Commerce\Contracts\PurchaseSummaryPort;
use App\Platform\Shared\Enterprise\Contracts\OrganizationSubscriptionPort;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Wires the Commerce module: config, migrations, routes, policies, the PaymentGateway binding
 * (Fake default, never a live gateway directly), the server-authoritative TaxCalculator, the
 * cross-context EntitlementPort, the fulfillment/refund/invoice/credit-note listeners, and the
 * dunning + subscription-renewal workers.
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

        // Server-authoritative tax calculator port.
        $this->app->bind(TaxCalculator::class, TaxService::class);

        // Cross-context entitlement boundary (lives in Shared): Commerce implements, others consume.
        $this->app->bind(EntitlementPort::class, EntitlementAdapter::class);
        $this->app->bind(PurchaseSummaryPort::class, PurchaseSummaryAdapter::class);
        // The manager portal's window onto what the organization bought. CRM authorizes the
        // caller and resolves which employees to seat; Commerce owns the seat maths and policy.
        $this->app->bind(CompanyEntitlementPort::class, CompanyEntitlementAdapter::class);

        // Org-subscription seat exposure for the CRM enterprise portal (seat summary + resize with
        // downgrade validation). Commerce implements; CRM consumes through this Shared port only.
        $this->app->bind(OrganizationSubscriptionPort::class, OrganizationSubscriptionExposureAdapter::class);

        $this->commands([
            RetryFailedPaymentsCommand::class,
            RenewDueSubscriptionsCommand::class,
        ]);
    }

    protected function bootDomain(): void
    {
        $this->registerRateLimiters();

        // Fulfillment is gated on BOTH payment and contract acceptance.
        Event::listen(OrderPaid::class, FulfillOnOrderPaid::class);
        Event::listen(ContractAccepted::class, FulfillOnContractAccepted::class);
        Event::listen(OrderRefunded::class, RevokeEnrollmentsOnRefund::class);

        // W05: snapshot invoice lines on payment; issue a credit note on (full) refund.
        Event::listen(OrderPaid::class, PopulateInvoiceLinesOnOrderPaid::class);
        Event::listen(OrderRefunded::class, IssueCreditNoteOnRefund::class);

        // W07: re-record a coupon redemption for an order paid via dunning after checkout's
        // compensation had released it (idempotent — no-op when a redemption already exists).
        Event::listen(OrderPaid::class, ReconcileCouponRedemptionOnOrderPaid::class);

        $this->registerSchedule();
    }

    private function registerRateLimiters(): void
    {
        // Checkout keyed by user (falls back to IP): bounds gateway calls + order creation.
        RateLimiter::for('commerce-checkout', fn (Request $r) => Limit::perMinute(10)
            ->by('checkout|'.($r->user()?->getAuthIdentifier() ?? $r->ip())));

        // Payment webhook is public and unauthenticated, so it can only be keyed by source IP.
        // The signature check inside the adapter is the real control; this is defence in depth.
        RateLimiter::for('commerce-webhook', fn (Request $r) => Limit::perMinute(60)
            ->by('payment-webhook|'.$r->ip()));

        // Coupon validation is public and unauthenticated: bound it to stop code brute-force /
        // enumeration. Keyed by authenticated user when present, else by source IP.
        RateLimiter::for('commerce-coupon', fn (Request $r) => Limit::perMinute(10)
            ->by('coupon-validate|'.($r->user()?->getAuthIdentifier() ?? $r->ip())));
    }

    /**
     * Hourly workers: retry failed one-off payments within the dunning window and advance the
     * subscription lifecycle (renew/cancel/grace/expire). Both commands are idempotent per run.
     */
    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // onOneServer(): withoutOverlapping()'s mutex is per-node, so on a multi-node deploy
            // both commands would otherwise fire on every node at once — combined with the dunning
            // retry that would race concurrent charges. onOneServer() elects a single runner.
            $schedule->command('commerce:retry-failed-payments')->hourly()->withoutOverlapping()->onOneServer();
            $schedule->command('commerce:renew-subscriptions')->hourly()->withoutOverlapping()->onOneServer();
        });
    }
}
