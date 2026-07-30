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
 * Amazon Payment Services (APS, formerly PayFort) — UAE / Saudi Arabia hosted-redirect gateway.
 *
 * charge() builds a signed PURCHASE redirection request and returns a 'pending' ChargeResult whose
 * redirectUrl is the hosted payment page with the signed parameters. merchant_reference is the
 * order public_id so it round-trips on the notification. APS amounts are integer minor units
 * already (value in the smallest currency unit), so amountMinor is passed through unchanged.
 *
 * The signature is APS's SHA (default SHA-256): request phrase, then every request parameter as
 * key=value sorted ascending by key, then the request phrase again — hashed. The notification
 * signature is verified the same way with the response phrase (fail closed on missing/invalid).
 * Events map to payment.succeeded | payment.failed | refund.succeeded by APS status/command.
 *
 * Illuminate HTTP client only — no vendor SDK.
 *
 * @phpstan-type ApsConfig array{access_code?: string, merchant_identifier?: string, request_phrase?: string, response_phrase?: string, sha_type?: string, base_url?: string, api_url?: string, return_url?: string, language?: string}
 */
class AmazonPaymentServicesGateway implements PaymentGateway
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Factory $http,
        private readonly array $config,
    ) {}

    public function charge(ChargeRequest $request): ChargeResult
    {
        $params = [
            'command' => 'PURCHASE',
            'access_code' => $this->str('access_code'),
            'merchant_identifier' => $this->str('merchant_identifier'),
            'merchant_reference' => $request->reference,
            'amount' => (string) $request->amountMinor,
            'currency' => strtoupper($request->currency),
            'language' => $this->str('language') ?: 'en',
            'customer_email' => is_string($request->metadata['email'] ?? null)
                ? $request->metadata['email']
                : 'noreply@helbaron.test',
            'return_url' => $this->str('return_url'),
        ];

        $params['signature'] = $this->sign($params, $this->str('request_phrase'));

        $base = $this->str('base_url') ?: 'https://checkout.payfort.com/FortAPI/paymentPage';
        $redirect = $base.(str_contains($base, '?') ? '&' : '?').http_build_query($params);

        return new ChargeResult(
            providerReference: $request->reference,
            status: 'pending',
            redirectUrl: $redirect,
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $params = [
            'command' => 'REFUND',
            'access_code' => $this->str('access_code'),
            'merchant_identifier' => $this->str('merchant_identifier'),
            'merchant_reference' => $request->providerReference,
            'fort_id' => $request->providerReference,
            'amount' => (string) $request->amountMinor,
            'currency' => strtoupper($request->currency),
            'language' => $this->str('language') ?: 'en',
        ];

        $params['signature'] = $this->sign($params, $this->str('request_phrase'));

        $response = $this->client()
            ->post($this->apiUrl(), $params)
            ->json();

        $status = is_array($response) ? (string) ($response['status'] ?? '') : '';

        // APS status 06 = Refund success.
        return new RefundResult(
            providerReference: $request->providerReference,
            status: $status === '06' ? 'succeeded' : 'failed',
        );
    }

    public function parseWebhook(string $payload, ?string $signature): WebhookEvent
    {
        /** @var array<string, mixed> $params */
        $params = json_decode($payload, true) ?: [];

        $provided = $signature ?? (is_string($params['signature'] ?? null) ? $params['signature'] : null);

        if ($provided === null || $provided === '') {
            throw new WebhookSignatureException('Missing APS signature.');
        }

        $signable = $params;
        unset($signable['signature']);

        if ($this->str('response_phrase') === '') {
            throw new WebhookSignatureException('APS signing secret is not configured (empty secret).');
        }

        $expected = $this->sign($signable, $this->str('response_phrase'));

        if (! hash_equals($expected, $provided)) {
            throw new WebhookSignatureException('Invalid APS signature.');
        }

        $command = strtoupper((string) ($params['command'] ?? ''));
        $status = (string) ($params['status'] ?? '');

        $type = match (true) {
            $command === 'REFUND' && $status === '06' => 'refund.succeeded',
            // 14 = Purchase success, 02 = Authorization success, 04 = Capture success.
            in_array($status, ['14', '02', '04'], true) => 'payment.succeeded',
            default => 'payment.failed',
        };

        return new WebhookEvent(
            id: (string) ($params['fort_id'] ?? $params['merchant_reference'] ?? ''),
            type: $type,
            orderReference: (string) ($params['merchant_reference'] ?? ''),
            providerReference: (string) ($params['fort_id'] ?? ''),
            raw: $params,
        );
    }

    /**
     * APS signature: phrase + sorted "key=value" params + phrase, hashed with the configured SHA.
     *
     * @param  array<string, mixed>  $params
     */
    private function sign(array $params, string $phrase): string
    {
        ksort($params);

        $concat = $phrase;
        foreach ($params as $key => $value) {
            $concat .= $key.'='.(is_scalar($value) ? (string) $value : '');
        }
        $concat .= $phrase;

        $algo = $this->str('sha_type') ?: 'sha256';

        return hash($algo, $concat);
    }

    private function client(): PendingRequest
    {
        return $this->http->acceptJson()->asJson();
    }

    private function apiUrl(): string
    {
        return $this->str('api_url') ?: 'https://paymentservices.payfort.com/FortAPI/paymentApi';
    }

    private function str(string $key): string
    {
        $value = $this->config[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
