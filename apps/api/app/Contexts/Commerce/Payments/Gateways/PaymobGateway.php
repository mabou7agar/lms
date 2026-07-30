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
 * Paymob (Accept) — Egypt / MENA hosted-iframe gateway.
 *
 * charge() runs Paymob's three-step handshake — auth token, ecommerce order, payment key — and
 * returns a 'pending' ChargeResult whose redirectUrl is the hosted iframe the shopper is sent to.
 * No confirmation is inferred here; the processed-transaction callback drives fulfilment.
 *
 * parseWebhook() verifies Paymob's HMAC-SHA512 over the canonically ordered transaction fields
 * (fail closed on a missing or mismatched hmac) and maps the transaction to
 * payment.succeeded | payment.failed | refund.succeeded. The order reference is recovered from
 * merchant_order_id, which charge() sets to the order public_id.
 *
 * Uses the Illuminate HTTP client only — no vendor SDK. Amounts are integer minor units
 * (Paymob "cents").
 *
 * @phpstan-type PaymobConfig array{api_key?: string, integration_id?: int|string, iframe_id?: int|string, base_url?: string, hmac_secret?: string, webhook_secret?: string}
 */
class PaymobGateway implements PaymentGateway
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Factory $http,
        private readonly array $config,
    ) {}

    public function charge(ChargeRequest $request): ChargeResult
    {
        $token = (string) ($this->client()
            ->post($this->url('/auth/tokens'), ['api_key' => $this->str('api_key')])
            ->throw()
            ->json('token') ?? '');

        $orderId = (string) ($this->client()
            ->post($this->url('/ecommerce/orders'), [
                'auth_token' => $token,
                'delivery_needed' => false,
                'amount_cents' => $request->amountMinor,
                'currency' => $request->currency,
                'merchant_order_id' => $request->reference,
                'items' => [],
            ])
            ->throw()
            ->json('id') ?? '');

        $paymentToken = (string) ($this->client()
            ->post($this->url('/acceptance/payment_keys'), [
                'auth_token' => $token,
                'amount_cents' => $request->amountMinor,
                'expiration' => 3600,
                'order_id' => $orderId,
                'currency' => $request->currency,
                'integration_id' => $this->str('integration_id'),
                'billing_data' => $this->billingData($request),
            ])
            ->throw()
            ->json('token') ?? '');

        $redirect = $this->url('/acceptance/iframes/'.$this->str('iframe_id'))
            .'?payment_token='.$paymentToken;

        return new ChargeResult(
            providerReference: $orderId,
            status: 'pending',
            redirectUrl: $redirect,
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $token = (string) ($this->client()
            ->post($this->url('/auth/tokens'), ['api_key' => $this->str('api_key')])
            ->throw()
            ->json('token') ?? '');

        $response = $this->client()
            ->post($this->url('/acceptance/void_refund/refund'), [
                'auth_token' => $token,
                'transaction_id' => $request->providerReference,
                'amount_cents' => $request->amountMinor,
            ])
            ->json();

        $ok = is_array($response) && ($response['success'] ?? false) === true;

        return new RefundResult(
            providerReference: $request->providerReference,
            status: $ok ? 'succeeded' : 'failed',
        );
    }

    public function parseWebhook(string $payload, ?string $signature): WebhookEvent
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($payload, true) ?: [];

        /** @var array<string, mixed> $obj */
        $obj = is_array($data['obj'] ?? null) ? $data['obj'] : [];

        $provided = $signature ?? (is_string($data['hmac'] ?? null) ? $data['hmac'] : null);

        if ($provided === null || $provided === '') {
            throw new WebhookSignatureException('Missing Paymob HMAC signature.');
        }

        if ($this->secret() === '') {
            throw new WebhookSignatureException('Paymob signing secret is not configured (empty secret).');
        }

        $expected = hash_hmac('sha512', $this->concatForHmac($obj), $this->secret());

        if (! hash_equals($expected, $provided)) {
            throw new WebhookSignatureException('Invalid Paymob HMAC signature.');
        }

        $refunded = ($obj['is_refunded'] ?? false) === true;
        $success = ($obj['success'] ?? false) === true;
        $voided = ($obj['is_voided'] ?? false) === true;

        $type = match (true) {
            $refunded => 'refund.succeeded',
            $success && ! $voided => 'payment.succeeded',
            default => 'payment.failed',
        };

        $order = is_array($obj['order'] ?? null) ? $obj['order'] : [];

        return new WebhookEvent(
            id: (string) ($obj['id'] ?? ''),
            type: $type,
            orderReference: (string) ($order['merchant_order_id'] ?? ''),
            providerReference: (string) ($obj['id'] ?? ''),
            raw: $data,
        );
    }

    /**
     * Canonical field ordering Paymob signs for a processed transaction. Booleans are lowercased
     * to 'true'/'false' exactly as Paymob serialises them before hashing.
     *
     * @param  array<string, mixed>  $obj
     */
    private function concatForHmac(array $obj): string
    {
        $order = is_array($obj['order'] ?? null) ? $obj['order'] : [];
        $source = is_array($obj['source_data'] ?? null) ? $obj['source_data'] : [];

        $parts = [
            $obj['amount_cents'] ?? '',
            $obj['created_at'] ?? '',
            $obj['currency'] ?? '',
            $this->boolStr($obj['error_occured'] ?? false),
            $this->boolStr($obj['has_parent_transaction'] ?? false),
            $obj['id'] ?? '',
            $obj['integration_id'] ?? '',
            $this->boolStr($obj['is_3d_secure'] ?? false),
            $this->boolStr($obj['is_auth'] ?? false),
            $this->boolStr($obj['is_capture'] ?? false),
            $this->boolStr($obj['is_refunded'] ?? false),
            $this->boolStr($obj['is_standalone_payment'] ?? false),
            $this->boolStr($obj['is_voided'] ?? false),
            $order['id'] ?? '',
            $obj['owner'] ?? '',
            $this->boolStr($obj['pending'] ?? false),
            $source['pan'] ?? '',
            $source['sub_type'] ?? '',
            $source['type'] ?? '',
            $this->boolStr($obj['success'] ?? false),
        ];

        return implode('', array_map(static fn ($value): string => (string) $value, $parts));
    }

    private function boolStr(mixed $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Paymob's payment_keys endpoint requires a billing_data block; values are drawn from charge
     * metadata when present, otherwise safe 'NA' placeholders for a hosted-page flow.
     *
     * @return array<string, string>
     */
    private function billingData(ChargeRequest $request): array
    {
        $meta = $request->metadata;

        $get = static fn (string $key): string => is_string($meta[$key] ?? null) ? $meta[$key] : 'NA';

        return [
            'first_name' => $get('first_name'),
            'last_name' => $get('last_name'),
            'email' => $get('email'),
            'phone_number' => $get('phone_number'),
            'apartment' => 'NA',
            'floor' => 'NA',
            'street' => 'NA',
            'building' => 'NA',
            'city' => 'NA',
            'country' => 'NA',
            'state' => 'NA',
            'postal_code' => 'NA',
        ];
    }

    private function client(): PendingRequest
    {
        return $this->http->acceptJson()->asJson();
    }

    private function url(string $path): string
    {
        $base = $this->str('base_url') ?: 'https://accept.paymob.com/api';

        return rtrim($base, '/').$path;
    }

    private function secret(): string
    {
        $secret = $this->str('hmac_secret');

        return $secret !== '' ? $secret : $this->str('webhook_secret');
    }

    private function str(string $key): string
    {
        $value = $this->config[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
