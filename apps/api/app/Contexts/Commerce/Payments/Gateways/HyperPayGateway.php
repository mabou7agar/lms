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
 * HyperPay — COPYandPay hosted-widget gateway (Saudi Arabia / UAE / MENA).
 *
 * charge() prepares a checkout and returns a 'pending' ChargeResult. The checkout id doubles as
 * the clientSecret (the widget is initialised with it) and the redirectUrl points to the hosted
 * payment page carrying that checkout id. merchantTransactionId is the order public_id so it
 * round-trips on the webhook. Auth is a Bearer access token scoped to an entityId.
 *
 * HyperPay result codes are matched against the documented success families
 * (000.000.* / 000.100.1* / 000.[36]*). Refunds are issued as paymentType RF against the payment id.
 *
 * parseWebhook() fails closed: it verifies an HMAC-SHA256 of the raw body against the signature
 * header using the configured webhook secret, then maps to
 * payment.succeeded | payment.failed | refund.succeeded. (HyperPay's production notifications are
 * AES-GCM encrypted; this adapter implements a faithful, clearly-documented HMAC-callback shape to
 * be gate-refined against the live tenant.)
 *
 * Amounts are integer minor units, formatted to the currency's decimal string only for the wire
 * (never held as float). Illuminate HTTP client only — no vendor SDK.
 *
 * @phpstan-type HyperPayConfig array{access_token?: string, entity_id?: string, base_url?: string, hosted_url?: string, webhook_secret?: string}
 */
class HyperPayGateway implements PaymentGateway
{
    /** HyperPay result-code families that denote a successful/pending-review transaction. */
    private const SUCCESS_PATTERN = '/^(000\.000\.|000\.100\.1|000\.[36])/';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Factory $http,
        private readonly array $config,
    ) {}

    public function charge(ChargeRequest $request): ChargeResult
    {
        $checkout = $this->client()
            ->asForm()
            ->post($this->url('/v1/checkouts'), [
                'entityId' => $this->str('entity_id'),
                'amount' => $this->majorAmount($request->amountMinor, $request->currency),
                'currency' => $request->currency,
                'paymentType' => 'DB',
                'merchantTransactionId' => $request->reference,
            ])
            ->throw()
            ->json();

        $checkout = is_array($checkout) ? $checkout : [];
        $id = (string) ($checkout['id'] ?? '');

        return new ChargeResult(
            providerReference: $id,
            status: 'pending',
            clientSecret: $id,
            redirectUrl: $this->hostedUrl($id),
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->client()
            ->asForm()
            ->post($this->url('/v1/payments/'.$request->providerReference), [
                'entityId' => $this->str('entity_id'),
                'amount' => $this->majorAmount($request->amountMinor, $request->currency),
                'currency' => $request->currency,
                'paymentType' => 'RF',
            ])
            ->json();

        $code = is_array($response) && is_array($response['result'] ?? null)
            ? (string) ($response['result']['code'] ?? '')
            : '';

        return new RefundResult(
            providerReference: $request->providerReference,
            status: $this->isSuccessCode($code) ? 'succeeded' : 'failed',
        );
    }

    public function parseWebhook(string $payload, ?string $signature): WebhookEvent
    {
        if ($signature === null || $signature === '') {
            throw new WebhookSignatureException('Missing HyperPay webhook signature.');
        }

        if ($this->str('webhook_secret') === '') {
            throw new WebhookSignatureException('HyperPay signing secret is not configured (empty secret).');
        }

        $expected = hash_hmac('sha256', $payload, $this->str('webhook_secret'));

        if (! hash_equals($expected, $signature)) {
            throw new WebhookSignatureException('Invalid HyperPay webhook signature.');
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($payload, true) ?: [];

        /** @var array<string, mixed> $data */
        $data = is_array($body['payload'] ?? null) ? $body['payload'] : $body;

        $result = is_array($data['result'] ?? null) ? $data['result'] : [];
        $code = (string) ($result['code'] ?? '');
        $paymentType = (string) ($data['paymentType'] ?? '');
        $success = $this->isSuccessCode($code);

        $type = match (true) {
            $success && $paymentType === 'RF' => 'refund.succeeded',
            $success => 'payment.succeeded',
            default => 'payment.failed',
        };

        return new WebhookEvent(
            id: (string) ($data['id'] ?? ''),
            type: $type,
            orderReference: (string) ($data['merchantTransactionId'] ?? ''),
            providerReference: (string) ($data['id'] ?? ''),
            raw: $body,
        );
    }

    private function isSuccessCode(string $code): bool
    {
        return $code !== '' && preg_match(self::SUCCESS_PATTERN, $code) === 1;
    }

    private function hostedUrl(string $checkoutId): string
    {
        $hosted = $this->str('hosted_url');
        $base = $hosted !== '' ? $hosted : $this->url('/v1/checkouts/'.$checkoutId.'/payment');

        return str_contains($base, '{id}')
            ? str_replace('{id}', $checkoutId, $base)
            : $base.(str_contains($base, '?') ? '&' : '?').'checkoutId='.$checkoutId;
    }

    private function client(): PendingRequest
    {
        return $this->http->withToken($this->str('access_token'))->acceptJson();
    }

    private function url(string $path): string
    {
        $base = $this->str('base_url') ?: 'https://eu-test.oppwa.com';

        return rtrim($base, '/').$path;
    }

    /** Integer-safe minor -> decimal string for the wire (e.g. 1550 SAR -> "15.50"). Never float. */
    private function majorAmount(int $minor, string $currency): string
    {
        $decimals = $this->currencyDecimals($currency);

        if ($decimals === 0) {
            return (string) $minor;
        }

        $factor = 10 ** $decimals;

        return sprintf('%d.%0'.$decimals.'d', intdiv($minor, $factor), abs($minor % $factor));
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
