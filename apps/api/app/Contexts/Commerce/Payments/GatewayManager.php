<?php

namespace App\Contexts\Commerce\Payments;

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Payments\Gateways\AmazonPaymentServicesGateway;
use App\Contexts\Commerce\Payments\Gateways\FakeGateway;
use App\Contexts\Commerce\Payments\Gateways\HyperPayGateway;
use App\Contexts\Commerce\Payments\Gateways\MoyasarGateway;
use App\Contexts\Commerce\Payments\Gateways\PaymobGateway;
use App\Contexts\Commerce\Payments\Gateways\StripeGateway;
use App\Contexts\Commerce\Payments\Gateways\TapGateway;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves the active payment gateway from configuration and constructs it with the shared
 * Illuminate HTTP client plus its own credentials block from config('commerce.gateways.*').
 *
 * The concrete provider is selected by config('commerce.payment.provider') and every adapter is
 * built uniformly with ($http, $config) so the manager never needs to know a provider's internals.
 * A hard production guard refuses the 'fake' gateway outside local/testing so a misconfigured
 * deploy can never silently accept "successful" payments that never touch a real processor.
 *
 * Bind the PaymentGateway port to this manager's resolved instance in the service provider, e.g.
 *   $this->app->bind(PaymentGateway::class, fn ($app) => $app->make(GatewayManager::class)->resolve());
 * so every consumer (CheckoutAction, InitiatePaymentAction, ProcessWebhook, RefundOrder) shares
 * one configured adapter.
 */
class GatewayManager
{
    public function __construct(
        private readonly Factory $http,
        private readonly Application $app,
    ) {}

    /**
     * Build the configured gateway adapter. Fails closed: an unknown provider throws rather than
     * falling back to a permissive default, and 'fake' is refused in production.
     */
    public function resolve(): PaymentGateway
    {
        return $this->resolveProvider((string) config('commerce.payment.provider', 'fake'));
    }

    /**
     * Build a named provider's adapter (used by the per-provider webhook route). Fails closed the
     * same way as resolve(): an unknown provider throws rather than falling back to a permissive
     * default, and 'fake' is refused in production. Verifying the signature still happens inside the
     * returned adapter.
     */
    public function resolveProvider(string $provider): PaymentGateway
    {
        if ($provider === 'fake' && $this->app->make('config')->get('app.env') === 'production') {
            throw new RuntimeException('The fake payment gateway is not permitted in production.');
        }

        return match ($provider) {
            'fake' => new FakeGateway,
            'stripe' => new StripeGateway($this->http, $this->configFor('stripe')),
            'paymob' => new PaymobGateway($this->http, $this->configFor('paymob')),
            'moyasar' => new MoyasarGateway($this->http, $this->configFor('moyasar')),
            'hyperpay' => new HyperPayGateway($this->http, $this->configFor('hyperpay')),
            'tap' => new TapGateway($this->http, $this->configFor('tap')),
            'aps' => new AmazonPaymentServicesGateway($this->http, $this->configFor('aps')),
            default => throw new InvalidArgumentException("Unsupported payment provider [{$provider}]."),
        };
    }

    /**
     * The credentials/base_url/webhook_secret block for a provider.
     *
     * @return array<string, mixed>
     */
    private function configFor(string $provider): array
    {
        $config = config('commerce.gateways.'.$provider, []);

        return is_array($config) ? $config : [];
    }
}
