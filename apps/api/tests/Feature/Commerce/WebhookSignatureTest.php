<?php

use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Payments\Gateways\FakeGateway;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
});

/**
 * The payment webhook is PUBLIC and its payload flips an order to Paid, which grants course
 * access. The signature is the only thing standing between an anonymous request and free courses,
 * so every way of not presenting a valid one must be refused.
 *
 * The bug these tests pin shut: verification was written as
 * `if ($signature !== null && ! hash_equals(...))`, so OMITTING the header skipped the check
 * entirely. Sending no signature at all was the exploit.
 */
function pendingOrder(): Order
{
    $user = User::factory()->create();

    return Order::factory()->create([
        'user_id' => $user->id,
        'status' => OrderStatus::Pending->value,
    ]);
}

function postWebhook(string $payload, ?string $signature): TestResponse
{
    $headers = $signature === null ? [] : ['HTTP_X-Signature' => $signature];

    return test()->call('POST', '/api/v1/payment/webhook', [], [], [], $headers, $payload);
}

function paidPayload(string $orderPublicId): string
{
    return json_encode([
        'id' => 'evt_1',
        'type' => 'payment.succeeded',
        'order_reference' => $orderPublicId,
    ], JSON_THROW_ON_ERROR);
}

it('refuses a webhook with no signature header at all', function () {
    $order = pendingOrder();
    $payload = paidPayload($order->public_id);

    // The exploit: no header, no verification, free course.
    postWebhook($payload, null)->assertStatus(400);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('refuses a webhook with a wrong signature', function () {
    $order = pendingOrder();
    $payload = paidPayload($order->public_id);

    postWebhook($payload, 'fake-signature=deadbeef')->assertStatus(400);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('refuses an empty signature header', function () {
    $order = pendingOrder();
    $payload = paidPayload($order->public_id);

    postWebhook($payload, '')->assertStatus(400);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('refuses a signature computed over a different payload', function () {
    $order = pendingOrder();

    // A replayed signature from another event must not authorize this one.
    $signature = FakeGateway::sign(paidPayload('some-other-order'));

    postWebhook(paidPayload($order->public_id), $signature)->assertStatus(400);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('accepts a correctly signed webhook', function () {
    $order = pendingOrder();
    $payload = paidPayload($order->public_id);

    postWebhook($payload, FakeGateway::sign($payload))->assertOk();

    expect($order->refresh()->status)->not->toBe(OrderStatus::Pending);
});
