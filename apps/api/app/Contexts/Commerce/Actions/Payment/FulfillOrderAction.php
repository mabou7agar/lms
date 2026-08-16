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

    /** The individual path, unchanged: the buyer is the student and is enrolled in what they bought. */
    private function enrolBuyer(Order $order): void
    {
        foreach ($order->items as $item) {
            foreach ($item->product->courses as $course) {
                $grant = OrderCourseGrant::firstOrCreate(
                    ['order_id' => $order->id, 'course_id' => $course->id],
                    ['granted_at' => now()],
                );

                if ($grant->wasRecentlyCreated) {
                    $this->grant->executeByUserId($order->user_id, $course->id, EnrollmentSource::Purchase);
                }
            }
        }
    }

    private function afterFulfilled(Order $order): bool
    {
        OrderFulfilled::dispatch($order->refresh());

        return true;
    }
}
