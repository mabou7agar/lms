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
 * Tap Payments — Kuwait / GCC hosted-redirect gateway.
 *
 * charge() creates a Tap charge and returns a 'pending' ChargeResult whose redirectUrl is the
 * transaction.url the shopper is sent to. reference.order carries the order public_id so it
 * round-trips on the webhook. Auth is a Bearer secret key.
 *
 * parseWebhook() verifies Tap's `hashstring`: an HMAC-SHA256 over the canonical
 * x_id/x_amount/x_currency/x_gateway_reference/x_payment_reference/x_status/x_created string using
 * the API secret (fail closed on a missing/invalid hash), then maps to
 * payment.succeeded | payment.failed | refund.succeeded.
 *
 * Amounts are integer minor units, formatted to the currency's decimal string only for the wire
 * (Tap KWD/BHD/OMR use 3 decimals). Illuminate HTTP client only — no vendor SDK.
 *
 * @phpstan-type TapConfig array{secret_key?: string, base_url?: string, redirect_url?: string, webhook_secret?: string}
 */
class TapGateway implements PaymentGateway
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Factory $http,
        private readonly array $config,
    ) {}

    public function charge(ChargeRequest $request): ChargeResult
    {
        $charge = $this->client()
            ->post($this->url('/v2/charges'), [
                'amount' => $this->majorAmount($request->amountMinor, $request->currency),
                'currency' => $request->currency,
                'threeDSecure' => true,
                'description' => $request->description !== '' ? $request->description : $request->reference,
                'reference' => ['order' => $request->reference],
                'source' => ['id' => 'src_all'],
                'redirect' => ['url' => $this->str('redirect_url')],
                'metadata' => ['order_reference' => $request->reference] + $request->metadata,
            ])
            ->throw()
            ->json();

        $charge = is_array($charge) ? $charge : [];
        $transaction = is_array($charge['transaction'] ?? null) ? $charge['transaction'] : [];

        return new ChargeResult(
            providerReference: (string) ($charge['id'] ?? ''),
            status: 'pending',
            redirectUrl: is_string($transaction['url'] ?? null) ? $transaction['url'] : null,
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->client()
            ->post($this->url('/v2/refunds'), [
                'charge_id' => $request->providerReference,
                'amount' => $this->majorAmount($request->amountMinor, $request->currency),
                'currency' => $request->currency,
                'reason' => 'requested_by_customer',
            ])
            ->json();

        $status = is_array($response) ? strtoupper((string) ($response['status'] ?? '')) : '';
        $ok = in_array($status, ['REFUNDED', 'PENDING', 'IN_PROGRESS'], true);

        return new RefundResult(
            providerReference: $request->providerReference,
            status: $ok ? 'succeeded' : 'failed',
        );
    }

    public function parseWebhook(string $payload, ?string $signature): WebhookEvent
    {
        /** @var array<string, mixed> $body */
        $body = json_decode($payload, true) ?: [];

        $provided = $signature ?? (is_string($body['hashstring'] ?? null) ? $body['hashstring'] : null);

        if ($provided === null || $provided === '') {
            throw new WebhookSignatureException('Missing Tap hashstring signature.');
        }

        if ($this->secret() === '') {
            throw new WebhookSignatureException('Tap signing secret is not configured (empty secret).');
        }

        if (! hash_equals($this->expectedHash($body), $provided)) {
            throw new WebhookSignatureException('Invalid Tap hashstring signature.');
        }

        $status = strtoupper((string) ($body['status'] ?? ''));
        $object = strtolower((string) ($body['object'] ?? 'charge'));

        $type = match (true) {
            $object === 'refund' && in_array($status, ['REFUNDED', 'ACCEPTED'], true) => 'refund.succeeded',
            $status === 'CAPTURED' => 'payment.succeeded',
            default => 'payment.failed',
        };

        $reference = is_array($body['reference'] ?? null) ? $body['reference'] : [];

        // Tap sends amounts as a currency-decimal string on the wire; convert back to integer minor
        // units for a refund event. Payment events leave amountMinor null.
        $amountMinor = $type === 'refund.succeeded' && is_scalar($body['amount'] ?? null)
            ? $this->minorAmount((string) $body['amount'], (string) ($body['currency'] ?? ''))
            : null;

        return new WebhookEvent(
            id: (string) ($body['id'] ?? ''),
            type: $type,
            orderReference: (string) ($reference['order'] ?? ''),
            providerReference: (string) ($body['id'] ?? ''),
            amountMinor: $amountMinor,
            raw: $body,
        );
    }

    /**
     * Tap's canonical to-be-hashed string, HMAC-SHA256 with the API secret.
     *
     * @param  array<string, mixed>  $body
     */
    private function expectedHash(array $body): string
    {
        $reference = is_array($body['reference'] ?? null) ? $body['reference'] : [];
        $gateway = is_array($body['gateway'] ?? null) ? $body['gateway'] : [];

        $amount = is_scalar($body['amount'] ?? null) ? (string) $body['amount'] : '';

        $toHash = 'x_id'.(string) ($body['id'] ?? '')
            .'x_amount'.$amount
            .'x_currency'.(string) ($body['currency'] ?? '')
            .'x_gateway_reference'.(string) ($gateway['reference'] ?? '')
            .'x_payment_reference'.(string) ($reference['payment'] ?? '')
            .'x_status'.(string) ($body['status'] ?? '')
            .'x_created'.(string) ($body['transaction']['created'] ?? $body['created'] ?? '');

        return hash_hmac('sha256', $toHash, $this->secret());
    }

    private function client(): PendingRequest
    {
        return $this->http->withToken($this->str('secret_key'))->acceptJson()->asJson();
    }

    private function url(string $path): string
    {
        $base = $this->str('base_url') ?: 'https://api.tap.company';

        return rtrim($base, '/').$path;
    }

    private function secret(): string
    {
        $secret = $this->str('webhook_secret');

        return $secret !== '' ? $secret : $this->str('secret_key');
    }

    /** Integer-safe minor -> decimal string for the wire (never float). */
    private function majorAmount(int $minor, string $currency): string
    {
        $decimals = $this->currencyDecimals($currency);

        if ($decimals === 0) {
            return (string) $minor;
        }

        $factor = 10 ** $decimals;

        return sprintf('%d.%0'.$decimals.'d', intdiv($minor, $factor), abs($minor % $factor));
    }

    /** Integer-safe decimal string -> minor units (never float). */
    private function minorAmount(string $amount, string $currency): int
    {
        $decimals = $this->currencyDecimals($currency);

        $negative = str_starts_with($amount, '-');
        $clean = ltrim($amount, '+-');

        $parts = explode('.', $clean, 2);
        $whole = $parts[0] === '' ? '0' : $parts[0];
        $fraction = $parts[1] ?? '';
        $fraction = substr(str_pad($fraction, $decimals, '0'), 0, $decimals);

        $minor = (int) $whole * (10 ** $decimals) + ($decimals > 0 ? (int) $fraction : 0);

        return $negative ? -$minor : $minor;
    }

    private function currencyDecimals(string $currency): int
    {
        return match (strtoupper($currency)) {
            'KWD', 'BHD', 'OMR', 'JOD', 'TND' => 3,
            'JPY', 'KRW' => 0,
            default => 2,
        };
    }

    private function str(string $key): string
    {
        $value = $this->config[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
