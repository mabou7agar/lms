<?php

namespace App\Contexts\Commerce\Actions\Payment;

use App\Contexts\Commerce\Enums\BuyerType;
use App\Contexts\Commerce\Events\OrderFulfilled;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\OrderCourseGrant;
use App\Contexts\Commerce\Services\CompanyEntitlementService;
use App\Contexts\Learning\Actions\Enrollment\GrantEnrollmentAction;
use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Analytics\AnalyticsEventName;
use App\Platform\Shared\Analytics\Contracts\AnalyticsEventRecorder;
use App\Platform\Shared\Analytics\Data\AnalyticsEventInput;
use Illuminate\Support\Carbon;

/**
 * Fulfils a paid order — ONLY when the order is paid AND its contract is accepted.
 *
 * What fulfilment MEANS depends on who bought. An individual is enrolled in what they bought, which
 * is what OrderCourseGrant has always recorded. A company is not: the person who ran the company's
 * card is an administrator, not the student, and enrolling them personally in fifty courses would be
 * both wrong and unrevokable. A company order instead provisions seat pools its manager hands out,
 * and nobody is enrolled until they do.
 *
 * Idempotent on both paths: order_course_grants for individuals, the unique (order, product) index on
 * company_entitlements for companies, so a webhook delivered twice changes nothing the second time.
 */
class FulfillOrderAction extends BaseAction
{
    public function __construct(
        private readonly GrantEnrollmentAction $grant,
        private readonly CompanyEntitlementService $companyEntitlements,
        private readonly AnalyticsEventRecorder $analytics,
    ) {}

    public function execute(Order $order): bool
    {
        return (bool) $this->transaction(function () use ($order): bool {
            $order = Order::whereKey($order->id)->lockForUpdate()->first();

            if (! $order->isPaid()) {
                return false;
            }

            $contract = $order->contract;
            if ($contract !== null && ! $contract->isAccepted()) {
                return false; // wait for acceptance
            }

            if ($order->fulfilled_at !== null) {
                return false; // already fulfilled
            }

            $order->load('items.product.courses');

            if ($order->buyer_type === BuyerType::Company) {
                $this->companyEntitlements->provisionForOrder($order);
            } else {
                $this->enrolBuyer($order);
            }

            $order->forceFill(['fulfilled_at' => now()])->save();

            return true;
        }) ? $this->afterFulfilled($order) : false;
    }

    /**
     * The individual path: the buyer is the student and is enrolled in what they bought — for as
     * long as they bought it for.
     *
     * The access window comes from the SAME product policy that governs a company purchase. It had
     * been applied only to company entitlements, so a product an admin sold as "12 months access"
     * was in fact granting an individual buyer lifetime access. Null stays null: a lifetime product,
     * and every product predating the policy, is unaffected.
     */
    private function enrolBuyer(Order $order): void
    {
        $purchasedAt = $order->paid_at ?? $order->placed_at ?? Carbon::now();

        foreach ($order->items as $item) {
            $product = $item->product;
            $expiresAt = $product->accessEndsAfter($purchasedAt);

            foreach ($product->courses as $course) {
                $grant = OrderCourseGrant::firstOrCreate(
                    ['order_id' => $order->id, 'course_id' => $course->id],
                    ['granted_at' => now()],
                );

                if ($grant->wasRecentlyCreated) {
                    $this->grant->executeByUserId(
                        $order->user_id,
                        $course->id,
                        EnrollmentSource::Purchase,
                        $expiresAt,
                    );
                }
            }
        }
    }

    private function afterFulfilled(Order $order): bool
    {
        $order = $order->refresh();

        // The authoritative revenue event, recorded where the money actually settled. Keyed on the
        // order so a redelivered webhook records the same sale once. The recorder cannot throw, so
        // nothing here can turn a completed purchase into a failed request.
        $this->analytics->record(new AnalyticsEventInput(
            name: AnalyticsEventName::OrderPaid->value,
            userId: (int) $order->user_id,
            organizationId: $order->organization_id === null ? null : (int) $order->organization_id,
            orderId: (int) $order->id,
            buyerType: $order->buyer_type?->value,
            valueMinor: (int) $order->total_minor,
            metadata: ['currency' => (string) $order->currency, 'items' => $order->items->count()],
            dedupKey: 'order_paid:'.$order->id,
            occurredAt: ($order->paid_at ?? Carbon::now())->toIso8601String(),
        ));

        OrderFulfilled::dispatch($order);

        return true;
    }
}
