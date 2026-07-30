<?php

namespace Tests\Unit\Payments;

use App\Contexts\Commerce\Exceptions\WebhookSignatureException;
use App\Contexts\Commerce\Payments\Gateways\PaymobGateway;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

/**
 * Paymob (MENA) adapter webhook signature verification. The adapter must verify Paymob's
 * HMAC-SHA512 over the canonically ordered transaction fields INSIDE the adapter and fail closed
 * on a missing or mismatched hmac. No network I/O happens in parseWebhook, so this is a pure unit
 * test constructed with a bare HTTP factory and a fixed hmac secret.
 */
class PaymobGatewaySignatureTest extends TestCase
{
    private const SECRET = 'paymob-test-hmac-secret';

    public function test_valid_signature_parses_a_successful_payment_event(): void
    {
        $gateway = $this->gateway();
        $obj = $this->transactionObject(['success' => true]);
        $payload = json_encode(['obj' => $obj, 'hmac' => $this->sign($obj)]);

        $event = $gateway->parseWebhook($payload, null);

        $this->assertSame('payment.succeeded', $event->type);
        $this->assertSame('ORD-PUBLIC-123', $event->orderReference);
        $this->assertSame('123456', $event->id);
    }

    public function test_refunded_transaction_maps_to_refund_succeeded(): void
    {
        $gateway = $this->gateway();
        $obj = $this->transactionObject(['success' => true, 'is_refunded' => true]);
        $payload = json_encode(['obj' => $obj]);

        $event = $gateway->parseWebhook($payload, $this->sign($obj));

        $this->assertSame('refund.succeeded', $event->type);
    }

    public function test_missing_signature_fails_closed(): void
    {
        $gateway = $this->gateway();
        $obj = $this->transactionObject(['success' => true]);
        $payload = json_encode(['obj' => $obj]);

        $this->expectException(WebhookSignatureException::class);

        $gateway->parseWebhook($payload, null);
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $gateway = $this->gateway();
        $obj = $this->transactionObject(['success' => true]);
        $payload = json_encode(['obj' => $obj, 'hmac' => str_repeat('0', 128)]);

        $this->expectException(WebhookSignatureException::class);

        $gateway->parseWebhook($payload, null);
    }

    private function gateway(): PaymobGateway
    {
        return new PaymobGateway(new Factory, ['hmac_secret' => self::SECRET]);
    }

    /**
     * A fully-populated Paymob processed-transaction object. Overrides let a test flip the
     * success/refund booleans while keeping every signed field present and deterministic.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function transactionObject(array $overrides = []): array
    {
        return array_replace([
            'amount_cents' => 9900,
            'created_at' => '2026-01-01T00:00:00Z',
            'currency' => 'EGP',
            'error_occured' => false,
            'has_parent_transaction' => false,
            'id' => 123456,
            'integration_id' => 111,
            'is_3d_secure' => true,
            'is_auth' => false,
            'is_capture' => false,
            'is_refunded' => false,
            'is_standalone_payment' => true,
            'is_voided' => false,
            'order' => ['id' => 777, 'merchant_order_id' => 'ORD-PUBLIC-123'],
            'owner' => 42,
            'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true,
        ], $overrides);
    }

    /**
     * Recompute the HMAC-SHA512 exactly as PaymobGateway::concatForHmac orders and serialises the
     * signed fields (booleans lowercased to 'true'/'false').
     *
     * @param  array<string, mixed>  $obj
     */
    private function sign(array $obj): string
    {
        $order = is_array($obj['order'] ?? null) ? $obj['order'] : [];
        $source = is_array($obj['source_data'] ?? null) ? $obj['source_data'] : [];

        $bool = static fn (mixed $value): string => $value ? 'true' : 'false';

        $parts = [
            $obj['amount_cents'] ?? '',
            $obj['created_at'] ?? '',
            $obj['currency'] ?? '',
            $bool($obj['error_occured'] ?? false),
            $bool($obj['has_parent_transaction'] ?? false),
            $obj['id'] ?? '',
            $obj['integration_id'] ?? '',
            $bool($obj['is_3d_secure'] ?? false),
            $bool($obj['is_auth'] ?? false),
            $bool($obj['is_capture'] ?? false),
            $bool($obj['is_refunded'] ?? false),
            $bool($obj['is_standalone_payment'] ?? false),
            $bool($obj['is_voided'] ?? false),
            $order['id'] ?? '',
            $obj['owner'] ?? '',
            $bool($obj['pending'] ?? false),
            $source['pan'] ?? '',
            $source['sub_type'] ?? '',
            $source['type'] ?? '',
            $bool($obj['success'] ?? false),
        ];

        $concat = implode('', array_map(static fn ($value): string => (string) $value, $parts));

        return hash_hmac('sha512', $concat, self::SECRET);
    }
}
