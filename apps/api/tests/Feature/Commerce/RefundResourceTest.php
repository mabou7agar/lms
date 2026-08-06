<?php

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\CommercePermission;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Contexts\Commerce\Filament\Resources\RefundResource;
use App\Contexts\Commerce\Filament\Resources\RefundResource\Pages\ListRefund;
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
use Database\Seeders\StaffRoleTemplatesSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/** A paid order with a settled charge, ready to be refunded (no product/course wiring needed). */
function refundableOrder(int $total = 10000): Order
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

/** Sign in as a super_admin on the admin panel so the resource + its header action are reachable. */
function actAsRefundAdmin(): User
{
    test()->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    test()->actingAs($admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

/** A gateway whose refund() always declines, to exercise the failed-settlement path. */
function decliningGateway(): PaymentGateway
{
    return new class implements PaymentGateway
    {
        public function charge(ChargeRequest $request): ChargeResult
        {
            return new ChargeResult($request->reference, 'succeeded');
        }

        public function refund(RefundRequest $request): RefundResult
        {
            return new RefundResult('rf_'.$request->providerReference, 'failed');
        }

        public function parseWebhook(string $payload, ?string $signature): WebhookEvent
        {
            return new WebhookEvent('evt', 'refund.failed', 'ref');
        }
    };
}

it('issues a first partial refund via the resource action and leaves the order paid', function () {
    actAsRefundAdmin();
    $order = refundableOrder(10000);

    Livewire::test(ListRefund::class)
        ->callAction('issueRefund', [
            'order_id' => $order->getKey(),
            'amount_minor' => 4000,
            'reason' => 'requested_by_customer',
        ]);

    $refund = Refund::where('order_id', $order->getKey())->sole();

    expect($refund->statusEnum())->toBe(RefundStatus::Succeeded)
        ->and($refund->amountMinor())->toBe(4000)
        ->and($order->fresh()->getAttribute('status'))->toBe(OrderStatus::Paid);
});

it('issues a second partial refund that consumes the remaining balance and flips the order to refunded', function () {
    actAsRefundAdmin();
    $order = refundableOrder(10000);

    Livewire::test(ListRefund::class)->callAction('issueRefund', [
        'order_id' => $order->getKey(),
        'amount_minor' => 4000,
        'reason' => 'requested_by_customer',
    ]);
    Livewire::test(ListRefund::class)->callAction('issueRefund', [
        'order_id' => $order->getKey(),
        'amount_minor' => 6000,
        'reason' => 'requested_by_customer',
    ]);

    expect((int) Refund::where('order_id', $order->getKey())->where('status', RefundStatus::Succeeded->value)->sum('amount_minor'))->toBe(10000)
        ->and($order->fresh()->getAttribute('status'))->toBe(OrderStatus::Refunded);
});

it('surfaces the balance guard: an over-refund is rejected and persists no refund', function () {
    actAsRefundAdmin();
    $order = refundableOrder(10000);

    Livewire::test(ListRefund::class)->callAction('issueRefund', [
        'order_id' => $order->getKey(),
        'amount_minor' => 20000,
        'reason' => 'requested_by_customer',
    ]);

    // The domain guard rejects before any ledger row is written; the order stays paid.
    expect(Refund::where('order_id', $order->getKey())->count())->toBe(0)
        ->and($order->fresh()->getAttribute('status'))->toBe(OrderStatus::Paid);
});

it('protects against a duplicate/retry: a second full refund of a refunded order is rejected', function () {
    actAsRefundAdmin();
    $order = refundableOrder(10000);

    // First full refund (blank amount = full remaining) settles and flips the order to refunded.
    Livewire::test(ListRefund::class)->callAction('issueRefund', [
        'order_id' => $order->getKey(),
        'reason' => 'requested_by_customer',
    ]);
    expect($order->fresh()->getAttribute('status'))->toBe(OrderStatus::Refunded);

    // A second attempt is rejected by the action (order no longer paid); no new refund appears.
    Livewire::test(ListRefund::class)->callAction('issueRefund', [
        'order_id' => $order->getKey(),
        'reason' => 'requested_by_customer',
    ]);

    expect(Refund::where('order_id', $order->getKey())->where('status', RefundStatus::Succeeded->value)->count())->toBe(1)
        ->and($order->fresh()->getAttribute('status'))->toBe(OrderStatus::Refunded);
});

it('records a gateway decline as a failed refund and keeps the order paid', function () {
    actAsRefundAdmin();
    app()->bind(PaymentGateway::class, fn (): PaymentGateway => decliningGateway());

    $order = refundableOrder(10000);

    Livewire::test(ListRefund::class)->callAction('issueRefund', [
        'order_id' => $order->getKey(),
        'amount_minor' => 5000,
        'reason' => 'requested_by_customer',
    ]);

    $refund = Refund::where('order_id', $order->getKey())->sole();

    expect($refund->statusEnum())->toBe(RefundStatus::Failed)
        ->and($order->fresh()->getAttribute('status'))->toBe(OrderStatus::Paid);
});

it('denies the refunds resource to a student and to a support agent, but allows a finance manager', function () {
    test()->seed(RolePermissionSeeder::class);
    foreach (CommercePermission::values() as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    test()->seed(StaffRoleTemplatesSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $student = User::factory()->create();
    $student->assignRole('student');

    $support = User::factory()->create();
    $support->assignRole('support_agent');

    $finance = User::factory()->create();
    $finance->assignRole('finance_manager');

    test()->actingAs($student, 'web');
    expect(RefundResource::canViewAny())->toBeFalse();

    test()->actingAs($support, 'web');
    expect(RefundResource::canViewAny())->toBeFalse();

    test()->actingAs($finance, 'web');
    expect(RefundResource::canViewAny())->toBeTrue();
});

it('lists refunds with a query count that does not scale with the number of rows', function () {
    actAsRefundAdmin();

    $seed = function (): void {
        $order = refundableOrder(10000);
        Refund::create([
            'order_id' => $order->getKey(),
            'amount_minor' => 2500,
            'currency' => 'SAR',
            'status' => RefundStatus::Succeeded->value,
            'reason' => 'requested_by_customer',
        ]);
    };

    foreach (range(1, 3) as $ignored) {
        $seed();
    }

    // Warm first-request initialization so both measurements compare like for like.
    Livewire::test(ListRefund::class);

    DB::enableQueryLog();
    Livewire::test(ListRefund::class);
    $threeRows = count(DB::getQueryLog());

    foreach (range(1, 3) as $ignored) {
        $seed();
    }

    DB::flushQueryLog();
    Livewire::test(ListRefund::class);
    $sixRows = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($sixRows)->toBe($threeRows);
});
