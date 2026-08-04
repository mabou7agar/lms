<?php

namespace App\Contexts\Commerce\Payments\Gateways;

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Exceptions\WebhookSignatureException;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * The ONLY class permitted to talk to Stripe. Uses the HTTP client (no vendor SDK) so nothing
 * else in Commerce couples to Stripe. Enabled by COMMERCE_PAYMENT_PROVIDER=stripe + keys.
 * Webhook signatures are verified against services.stripe.webhook_secret using Stripe's
 * "t=...,v1=..." scheme with a timestamp tolerance.
 */
class StripeGateway implements PaymentGateway
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly HttpClient $http,
        private readonly array $config,
    ) {}

    public function charge(ChargeRequest $request): ChargeResult
    {
        $client = $this->client()->asForm();

        if ($request->idempotencyKey !== null) {
            $client = $client->withHeaders(['Idempotency-Key' => $request->idempotencyKey]);
        }

        $response = $client->post('/v1/payment_intents', array_filter([
            'amount' => $request->amountMinor,
            'currency' => strtolower($request->currency),
            'description' => $request->description !== '' ? $request->description : null,
            'metadata[order_reference]' => $request->reference,
        ] + $this->metadata($request->metadata)))->throw()->json();

        return new ChargeResult(
            providerReference: (string) ($response['id'] ?? ''),
            status: $this->mapIntentStatus((string) ($response['status'] ?? 'requires_payment_method')),
            clientSecret: $response['client_secret'] ?? null,
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->client()->asForm()->post('/v1/refunds', [
            'payment_intent' => $request->providerReference,
            'amount' => $request->amountMinor,
        ])->throw()->json();

        return new RefundResult(
            providerReference: (string) ($response['id'] ?? ''),
            status: ((string) ($response['status'] ?? 'failed')) === 'succeeded' ? 'succeeded' : 'failed',
        );
    }

    public function parseWebhook(string $payload, ?string $signature): WebhookEvent
    {
        $this->verifySignature($payload, $signature);

        $data = json_decode($payload, true) ?: [];
        $object = is_array($data['data']['object'] ?? null) ? $data['data']['object'] : [];

        $type = $this->mapEventType((string) ($data['type'] ?? ''));

        return new WebhookEvent(
            id: (string) ($data['id'] ?? ''),
            type: $type,
            orderReference: (string) ($object['metadata']['order_reference'] ?? ''),
            providerReference: $object['id'] ?? null,
            amountMinor: $type === 'refund.succeeded' ? $this->refundedAmount($object) : null,
            raw: $data,
        );
    }

    /**
     * The refunded amount in integer minor units. A charge.refunded event exposes the cumulative
     * amount_refunded on the charge object; a refund.* event exposes the refund's own amount.
     *
     * @param  array<string, mixed>  $object
     */
    private function refundedAmount(array $object): ?int
    {
        $value = $object['amount_refunded'] ?? $object['amount'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** Verify Stripe's Signature header: "t=timestamp,v1=hmac". */
    private function verifySignature(string $payload, ?string $signature): void
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');

        if ($signature === null || $secret === '') {
            throw new WebhookSignatureException;
        }

        $parts = [];
        foreach (explode(',', $signature) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[trim($k)] = trim($v);
        }

        $timestamp = $parts['t'] ?? null;
        $provided = $parts['v1'] ?? null;

        if ($timestamp === null || $provided === null) {
            throw new WebhookSignatureException;
        }

        $tolerance = (int) ($this->config['webhook_tolerance'] ?? 300);
        if ($tolerance > 0 && abs(time() - (int) $timestamp) > $tolerance) {
            throw new WebhookSignatureException;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        if (! hash_equals($expected, $provided)) {
            throw new WebhookSignatureException;
        }
    }

    private function client(): PendingRequest
    {
        $secret = (string) ($this->config['secret'] ?? '');

        if ($secret === '') {
            throw new RuntimeException('Stripe secret is not configured (set STRIPE_SECRET).');
        }

        return $this->http
            ->baseUrl((string) ($this->config['base_url'] ?? 'https://api.stripe.com'))
            ->withToken($secret)
            ->acceptJson();
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    private function metadata(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $key => $value) {
            $out["metadata[{$key}]"] = $value;
        }

        return $out;
    }

    private function mapIntentStatus(string $status): string
    {
        return match ($status) {
            'succeeded' => 'succeeded',
            'canceled' => 'failed',
            default => 'pending',
        };
    }

    private function mapEventType(string $type): string
    {
        return match ($type) {
            'payment_intent.succeeded', 'charge.succeeded' => 'payment.succeeded',
            'payment_intent.payment_failed', 'charge.failed' => 'payment.failed',
            'charge.refunded', 'refund.succeeded' => 'refund.succeeded',
            default => $type,
        };
    }
}
