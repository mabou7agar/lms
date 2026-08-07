<?php

use App\Contexts\Commerce\Actions\Refund\IssueRefundAction;
use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Contexts\Commerce\Filament\Resources\RefundResource;
use App\Contexts\Commerce\Filament\Resources\RefundResource\Pages\ViewRefund;
use App\Contexts\Commerce\Models\CreditNote;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\PaymentTransaction;
use App\Contexts\Commerce\Models\Refund;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Audit\AuditLog;
use Database\Seeders\StaffRoleTemplatesSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function retryRefundAdmin(): User
{
    test()->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    test()->actingAs($admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

/** A paid order with a settled charge, ready to be refunded. */
function retryPaidOrder(int $total = 10000): Order
{
    $user = User::factory()->create();

    $order = Order::create([
        'user_id' => $user->id,
        'status' => OrderStatus::Paid->value,
        'currency' => 'SAR',
        'subtotal_minor' => $total,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => $total,
        'placed_at' => now(),
    ]);
    $order->forceFill(['paid_at' => now()])->save();

    PaymentTransaction::create([
        'order_id' => $order->getKey(),
        'provider' => 'fake',
        'provider_reference' => 'ch_'.$order->getKey(),
        'type' => 'charge',
        'status' => 'succeeded',
        'amount_minor' => $total,
        'currency' => 'SAR',
    ]);

    return $order;
}

function retryGateway(bool $refundSucceeds): PaymentGateway
{
    return new class($refundSucceeds) implements PaymentGateway
    {
        public function __construct(public bool $refundSucceeds) {}

        public function charge(ChargeRequest $request): ChargeResult
        {
            return new ChargeResult($request->reference, 'succeeded');
        }

        public function refund(RefundRequest $request): RefundResult
        {
            return new RefundResult('rf_'.$request->providerReference, $this->refundSucceeds ? 'succeeded' : 'failed');
        }

        public function parseWebhook(string $payload, ?string $signature): WebhookEvent
        {
            return new WebhookEvent('evt', 'refund.failed', 'ref');
        }
    };
}

/** Produce a genuinely FAILED refund through the engine (its row is thereafter immutable). */
function failedRefundFor(Order $order, ?int $amountMinor = null): Refund
{
    app()->bind(PaymentGateway::class, fn (): PaymentGateway => retryGateway(false));

    try {
        app(IssueRefundAction::class)->execute($order, $amountMinor);
    } catch (Throwable) {
        // The engine re-raises a gateway decline after settling the refund as failed — expected.
    }

    return Refund::where('order_id', $order->getKey())->where('status', RefundStatus::Failed->value)->latest('id')->firstOrFail();
}

it('R4: the retry action is visible only for a FAILED refund', function () {
    retryRefundAdmin();
    $order = retryPaidOrder();
    $failed = failedRefundFor($order);

    Livewire::test(ViewRefund::class, ['record' => $failed->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertActionVisible('retryRefund');
});

it('R4: the retry action is hidden for a succeeded refund', function () {
    retryRefundAdmin();
    $order = retryPaidOrder();

    $succeeded = Refund::create([
        'order_id' => $order->getKey(),
        'amount_minor' => 4000,
        'currency' => 'SAR',
        'status' => RefundStatus::Succeeded->value,
        'reason' => 'requested_by_customer',
    ]);

    Livewire::test(ViewRefund::class, ['record' => $succeeded->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertActionHidden('retryRefund');
});

it('R4: the retry action is hidden for a pending (in-flight) refund', function () {
    retryRefundAdmin();
    $order = retryPaidOrder();

    $pending = Refund::create([
        'order_id' => $order->getKey(),
        'amount_minor' => 4000,
        'currency' => 'SAR',
        'status' => RefundStatus::Pending->value,
        'reason' => 'requested_by_customer',
    ]);

    Livewire::test(ViewRefund::class, ['record' => $pending->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertActionHidden('retryRefund');
});

it('R4: retrying a failed refund issues a NEW refund and leaves the immutable failed row untouched', function () {
    retryRefundAdmin();
    $order = retryPaidOrder(10000);
    $failed = failedRefundFor($order, 10000);

    // Now let the gateway succeed and retry.
    app()->bind(PaymentGateway::class, fn (): PaymentGateway => retryGateway(true));

    Livewire::test(ViewRefund::class, ['record' => $failed->getAttribute('public_id')])
        ->callAction('retryRefund');

    // The original failed row is unchanged (financial immutability preserved).
    $originalFresh = $failed->fresh();
    expect($originalFresh->statusEnum())->toBe(RefundStatus::Failed)
        ->and($originalFresh->amountMinor())->toBe(10000);

    // A brand-new succeeded refund exists, and the order flipped to refunded.
    $succeeded = Refund::where('order_id', $order->getKey())->where('status', RefundStatus::Succeeded->value)->get();
    expect($succeeded)->toHaveCount(1)
        ->and($succeeded->first()->getKey())->not->toBe($failed->getKey())
        ->and($order->fresh()->getAttribute('status'))->toBe(OrderStatus::Refunded);

    // The retry was audited.
    expect(AuditLog::where('action', 'commerce.refund.retry_attempted')->count())->toBe(1);
});

it('R4: a double retry cannot over-refund or mint a duplicate credit note (idempotent)', function () {
    retryRefundAdmin();
    $order = retryPaidOrder(10000);
    $failed = failedRefundFor($order, 10000);

    app()->bind(PaymentGateway::class, fn (): PaymentGateway => retryGateway(true));

    // First retry succeeds and fully refunds the order.
    Livewire::test(ViewRefund::class, ['record' => $failed->getAttribute('public_id')])->callAction('retryRefund');
    // Second retry of the same failed row: the order is no longer paid, so the engine rejects it.
    Livewire::test(ViewRefund::class, ['record' => $failed->getAttribute('public_id')])->callAction('retryRefund');

    // No over-refund: exactly one succeeded refund, summing to the paid total.
    $succeeded = Refund::where('order_id', $order->getKey())->where('status', RefundStatus::Succeeded->value);
    expect($succeeded->count())->toBe(1)
        ->and((int) $succeeded->sum('amount_minor'))->toBe(10000);

    // No duplicate credit note: at most one per order.
    expect(CreditNote::where('order_id', $order->getKey())->count())->toBeLessThanOrEqual(1);
});

it('R4: retry is a finance-only capability — support cannot manage refunds, finance can', function () {
    test()->seed(RolePermissionSeeder::class);
    foreach (\App\Contexts\Commerce\Enums\CommercePermission::values() as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    test()->seed(StaffRoleTemplatesSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $support = User::factory()->create();
    $support->assignRole('support_agent');

    $finance = User::factory()->create();
    $finance->assignRole('finance_manager');

    test()->actingAs($support, 'web');
    expect(RefundResource::userCanManage())->toBeFalse();

    test()->actingAs($finance, 'web');
    expect(RefundResource::userCanManage())->toBeTrue();
});
