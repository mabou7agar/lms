<?php

namespace App\Contexts\Commerce\Payments\Gateways;

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Exceptions\WebhookSignatureException;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;

/**
 * Moyasar — Saudi Arabia hosted-invoice gateway (SAR-first).
 *
 * charge() creates a Moyasar invoice and returns a 'pending' ChargeResult whose redirectUrl is the
 * hosted invoice page. The order public_id is stored on both merchant_order_id-style metadata and
 * the invoice description so it round-trips on the webhook. Amounts are integer minor units
 * (halalas). Auth is HTTP Basic with the secret key as the username.
 *
 * parseWebhook() verifies an HMAC-SHA256 of the raw body against the signature header using the
 * configured webhook secret (fail closed on missing/invalid), then maps the event to
 * payment.succeeded | payment.failed | refund.succeeded. The order reference is read from
 * data.metadata.order_reference.
 *
 * Illuminate HTTP client only — no vendor SDK.
 *
 * @phpstan-type MoyasarConfig array{secret_key?: string, base_url?: string, callback_url?: string, webhook_secret?: string}
 */
class MoyasarGateway implements PaymentGateway
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Factory $http,
        private readonly array $config,
    ) {}

    public function charge(ChargeRequest $request): ChargeResult
    {
        $invoice = $this->client()
            ->post($this->url('/v1/invoices'), [
                'amount' => $request->amountMinor,
                'currency' => $request->currency,
                'description' => $request->description !== '' ? $request->description : $request->reference,
                'callback_url' => $this->str('callback_url'),
                'metadata' => ['order_reference' => $request->reference] + $request->metadata,
            ])
            ->throw()
            ->json();

        $invoice = is_array($invoice) ? $invoice : [];

        return new ChargeResult(
            providerReference: (string) ($invoice['id'] ?? ''),
            status: 'pending',
            redirectUrl: is_string($invoice['url'] ?? null) ? $invoice['url'] : null,
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->client()
            ->post($this->url('/v1/payments/'.$request->providerReference.'/refund'), [
                'amount' => $request->amountMinor,
            ])
            ->json();

        $status = is_array($response) ? (string) ($response['status'] ?? '') : '';
        $ok = in_array($status, ['refunded', 'refunding'], true);

        return new RefundResult(
            providerReference: $request->providerReference,
            status: $ok ? 'succeeded' : 'failed',
        );
    }

    public function parseWebhook(string $payload, ?string $signature): WebhookEvent
    {
        if ($signature === null || $signature === '') {
            throw new WebhookSignatureException('Missing Moyasar webhook signature.');
        }

        if ($this->str('webhook_secret') === '') {
            throw new WebhookSignatureException('Moyasar signing secret is not configured (empty secret).');
        }

        $expected = hash_hmac('sha256', $payload, $this->str('webhook_secret'));

        if (! hash_equals($expected, $signature)) {
            throw new WebhookSignatureException('Invalid Moyasar webhook signature.');
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($payload, true) ?: [];

        /** @var array<string, mixed> $data */
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        $eventType = (string) ($body['type'] ?? '');
        $status = (string) ($data['status'] ?? '');

        $type = match (true) {
            $eventType === 'payment_refunded' || $status === 'refunded' => 'refund.succeeded',
            $eventType === 'payment_paid' || $status === 'paid' => 'payment.succeeded',
            default => 'payment.failed',
        };

        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        // Moyasar amounts are integer minor units (halalas). A refunded payment reports the amount
        // refunded so far in `refunded`; fall back to the payment `amount` for a full refund.
        // Payment events leave amountMinor null.
        $amountMinor = null;
        if ($type === 'refund.succeeded') {
            $refunded = $data['refunded'] ?? $data['amount'] ?? null;
            $amountMinor = is_numeric($refunded) ? (int) $refunded : null;
        }

        return new WebhookEvent(
            id: (string) ($body['id'] ?? $data['id'] ?? ''),
            type: $type,
            orderReference: (string) ($metadata['order_reference'] ?? ''),
            providerReference: (string) ($data['id'] ?? ''),
            amountMinor: $amountMinor,
            raw: $body,
        );
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->withBasicAuth($this->str('secret_key'), '')
            ->acceptJson()
            ->asJson();
    }

    private function url(string $path): string
    {
        $base = $this->str('base_url') ?: 'https://api.moyasar.com';

        return rtrim($base, '/').$path;
    }

    private function str(string $key): string
    {
        $value = $this->config[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
