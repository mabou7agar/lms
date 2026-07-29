<?php

use App\Contexts\Commerce\Exceptions\WebhookSignatureException;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\GatewayManager;
use App\Contexts\Commerce\Payments\Gateways\FakeGateway;
use App\Contexts\Commerce\Payments\Gateways\StripeGateway;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;

function stripeGateway(): StripeGateway
{
    return new StripeGateway(app(HttpClient::class), [
        'base_url' => 'https://api.stripe.com',
        'secret' => 'sk_test_x',
        'webhook_secret' => 'whsec_test',
        'webhook_tolerance' => 300,
    ]);
}

function stripeSign(string $payload, string $secret = 'whsec_test', ?int $ts = null): string
{
    $ts ??= time();

    return 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$payload, $secret);
}

it('charges via the Stripe API and maps the intent', function () {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response([
        'id' => 'pi_123', 'status' => 'requires_payment_method', 'client_secret' => 'pi_123_secret',
    ])]);

    $result = stripeGateway()->charge(new ChargeRequest('order-abc', 2500, 'USD', 'Course'));

    expect($result->providerReference)->toBe('pi_123')
        ->and($result->status)->toBe('pending')
        ->and($result->clientSecret)->toBe('pi_123_secret');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/payment_intents')
        && $req->hasHeader('Authorization', 'Bearer sk_test_x')
        && $req['amount'] == 2500 && $req['currency'] === 'usd'
        && $req['metadata[order_reference]'] === 'order-abc');
});

it('refunds via the Stripe API', function () {
    Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'])]);

    $result = stripeGateway()->refund(new RefundRequest('pi_123', 2500, 'USD'));

    expect($result->isSucceeded())->toBeTrue()->and($result->providerReference)->toBe('re_1');
});

it('verifies a valid Stripe webhook signature and normalizes the event', function () {
    $payload = json_encode([
        'id' => 'evt_1', 'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_123', 'metadata' => ['order_reference' => 'order-abc']]],
    ]);

    $event = stripeGateway()->parseWebhook($payload, stripeSign($payload));

    expect($event->id)->toBe('evt_1')
        ->and($event->type)->toBe('payment.succeeded')
        ->and($event->orderReference)->toBe('order-abc')
        ->and($event->providerReference)->toBe('pi_123');
});

it('rejects a tampered Stripe webhook signature', function () {
    $payload = json_encode(['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => []]]);

    stripeGateway()->parseWebhook($payload, stripeSign($payload, 'wrong_secret'));
})->throws(WebhookSignatureException::class);

it('rejects a stale Stripe webhook timestamp (replay protection)', function () {
    $payload = json_encode(['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => []]]);

    stripeGateway()->parseWebhook($payload, stripeSign($payload, 'whsec_test', time() - 10_000));
})->throws(WebhookSignatureException::class);

it('selects the gateway by config (fake default, stripe when configured)', function () {
    config()->set('commerce.payment.provider', 'fake');
    expect(app(GatewayManager::class)->resolve())->toBeInstanceOf(FakeGateway::class);

    config()->set('commerce.payment.provider', 'stripe');
    config()->set('services.stripe', ['base_url' => 'https://api.stripe.com', 'secret' => 'sk', 'webhook_secret' => 'wh', 'webhook_tolerance' => 300]);
    expect(app(GatewayManager::class)->resolve())->toBeInstanceOf(StripeGateway::class);
});

it('refuses to resolve the fake gateway in production', function () {
    // The fake gateway approves every charge. Resolving it in production means real orders are
    // fulfilled without a real payment, so a misconfigured (or unset) provider must fail loudly at
    // boot rather than silently degrade into free checkout.
    config()->set('app.env', 'production');
    config()->set('commerce.payment.provider', 'fake');

    app(GatewayManager::class)->resolve();
})->throws(RuntimeException::class);

it('still resolves stripe in production', function () {
    config()->set('app.env', 'production');
    config()->set('commerce.payment.provider', 'stripe');
    config()->set('services.stripe', ['base_url' => 'https://api.stripe.com', 'secret' => 'sk', 'webhook_secret' => 'wh', 'webhook_tolerance' => 300]);

    expect(app(GatewayManager::class)->resolve())->toBeInstanceOf(StripeGateway::class);
});
