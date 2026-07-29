<?php

namespace App\Contexts\Commerce\Payments;

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Payments\Gateways\FakeGateway;
use App\Contexts\Commerce\Payments\Gateways\StripeGateway;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpClient;

/**
 * Resolves the configured PaymentGateway (fake | stripe) from config/commerce.php. The Stripe
 * adapter receives services.stripe config here so no other code reads vendor secrets.
 */
class GatewayManager
{
    public function __construct(private readonly Container $app) {}

    public function resolve(): PaymentGateway
    {
        $provider = (string) config('commerce.payment.provider', 'fake');

        // The fake gateway approves every charge. Reaching it in production means real orders are
        // fulfilled without a real payment, so an unset or mistyped COMMERCE_PAYMENT_PROVIDER must
        // fail loudly at boot rather than silently degrade into free checkout. Defence in depth:
        // the webhook signature check is the real control, this stops the misconfiguration.
        if ($provider !== 'stripe' && $this->app->make('config')->get('app.env') === 'production') {
            throw new \RuntimeException(
                "Refusing to use the '{$provider}' payment gateway in production. "
                .'Set COMMERCE_PAYMENT_PROVIDER=stripe.',
            );
        }

        return match ($provider) {
            'stripe' => new StripeGateway($this->app->make(HttpClient::class), (array) config('services.stripe')),
            default => $this->app->make(FakeGateway::class),
        };
    }
}
