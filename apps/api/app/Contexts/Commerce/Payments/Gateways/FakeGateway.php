<?php

namespace App\Contexts\Commerce\Payments\Gateways;

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Exceptions\WebhookSignatureException;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use Illuminate\Support\Str;

/**
 * Default gateway for local/test. Never contacts a real processor. Charges return a pending
 * intent; a webhook (posted by the test/frontend) then confirms it.
 */
class FakeGateway implements PaymentGateway
{
    public function charge(ChargeRequest $request): ChargeResult
    {
        $reference = 'fake_'.Str::random(24);

        return new ChargeResult(
            providerReference: $reference,
            status: 'pending',
            clientSecret: 'cs_'.Str::random(24),
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        return new RefundResult(
            providerReference: 'fake_re_'.Str::random(20),
            status: 'succeeded',
        );
    }

    /**
     * Verify the fake provider's signature.
     *
     * A MISSING signature is a failure, exactly like a wrong one. Treating null as "nothing to
     * check" turns the webhook into an unauthenticated write: this route is public and the payload
     * flips an order to Paid and grants course access, so omitting the header was enough to obtain
     * paid courses for free. Fail closed, matching StripeGateway::verifySignature().
     */
    public function parseWebhook(string $payload, ?string $signature): WebhookEvent
    {
        $secret = (string) config('commerce.payment.webhook_secret');
        $expected = 'fake-signature='.hash_hmac('sha256', $payload, $secret);

        if ($signature === null || $secret === '' || ! hash_equals($expected, $signature)) {
            throw new WebhookSignatureException;
        }

        $data = json_decode($payload, true) ?: [];

        $type = (string) ($data['type'] ?? 'payment.succeeded');

        // A refund webhook may carry the refunded amount in integer minor units; payment events
        // leave amountMinor null.
        $amountMinor = $type === 'refund.succeeded' && is_numeric($data['amount_minor'] ?? null)
            ? (int) $data['amount_minor']
            : null;

        return new WebhookEvent(
            id: (string) ($data['id'] ?? Str::uuid()),
            type: $type,
            orderReference: (string) ($data['order_reference'] ?? ''),
            providerReference: $data['provider_reference'] ?? null,
            amountMinor: $amountMinor,
            raw: $data,
        );
    }

    /** Helper used by tests to build a validly-signed webhook payload. */
    public static function sign(string $payload): string
    {
        return 'fake-signature='.hash_hmac('sha256', $payload, (string) config('commerce.payment.webhook_secret'));
    }
}
