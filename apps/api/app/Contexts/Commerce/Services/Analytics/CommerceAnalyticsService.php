<?php

namespace App\Contexts\Commerce\Services\Analytics;

use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\Refund;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionChange;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Platform\Shared\Services\BaseService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Read-only commerce analytics. Every method is an aggregate query over the existing Commerce
 * tables — no writes, no gateway I/O, no new tables. All money is integer minor units throughout;
 * ratios that would otherwise fall to a float (AOV, normalized MRR) are floored to whole minor
 * units so the surface never emits a fractional currency value.
 *
 * Range-scoped figures (revenue, refunds, orders, coupons, churn) accept an inclusive [from, to]
 * window resolved from ISO date/datetime strings; a date-only "to" is stretched to the end of that
 * day so the final day is fully counted. Point-in-time figures (MRR/ARR, active subscribers) reflect
 * the live subscription base and ignore the window by design.
 */
class CommerceAnalyticsService extends BaseService
{
    /**
     * Full analytics summary for the [from, to] window. from/to are ISO date or datetime strings;
     * null means unbounded (from = epoch, to = now).
     *
     * @return array{
     *     range: array{from: string, to: string},
     *     revenue_minor: int,
     *     refunds_minor: int,
     *     net_revenue_minor: int,
     *     orders_count: int,
     *     aov_minor: int,
     *     coupon: array{redemptions: int, discount_minor: int},
     *     mrr_minor: int,
     *     arr_minor: int,
     *     active_subscribers: int,
     *     churn_count: int
     * }
     */
    public function summary(?string $from = null, ?string $to = null): array
    {
        [$start, $end] = $this->resolveRange($from, $to);

        $revenue = $this->revenueMinor($start, $end);
        $refunds = $this->refundsMinor($start, $end);
        $orders = $this->ordersCount($start, $end);
        $mrr = $this->mrrMinor();

        return [
            'range' => [
                'from' => $start->toIso8601String(),
                'to' => $end->toIso8601String(),
            ],
            'revenue_minor' => $revenue,
            'refunds_minor' => $refunds,
            'net_revenue_minor' => $revenue - $refunds,
            'orders_count' => $orders,
            'aov_minor' => $orders > 0 ? intdiv($revenue, $orders) : 0,
            'coupon' => $this->couponUsage($start, $end),
            'mrr_minor' => $mrr,
            'arr_minor' => $mrr * 12,
            'active_subscribers' => $this->activeSubscriberCount(),
            'churn_count' => $this->churnCount($start, $end),
        ];
    }

    /** Gross revenue: the summed total of paid orders whose payment landed inside the window. */
    public function revenueMinor(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) Order::query()
            ->where('status', OrderStatus::Paid->value)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->sum('total_minor');
    }

    /** Refunds total: the summed amount of refunds that succeeded inside the window. */
    public function refundsMinor(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) $this->refundQuery()
            ->whereBetween('processed_at', [$from, $to])
            ->sum('amount_minor');
    }

    /** Count of paid orders inside the window. */
    public function ordersCount(CarbonInterface $from, CarbonInterface $to): int
    {
        return Order::query()
            ->where('status', OrderStatus::Paid->value)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->count();
    }

    /**
     * Coupon usage across paid orders in the window: how many carried a coupon (redemptions) and the
     * total discount those orders granted, in minor units.
     *
     * @return array{redemptions: int, discount_minor: int}
     */
    public function couponUsage(CarbonInterface $from, CarbonInterface $to): array
    {
        $base = Order::query()
            ->where('status', OrderStatus::Paid->value)
            ->whereNotNull('coupon_id')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to]);

        return [
            'redemptions' => (clone $base)->count(),
            'discount_minor' => (int) $base->sum('discount_minor'),
        ];
    }

    /**
     * Monthly recurring revenue from the live active subscription base, in minor units. Each
     * subscription's period amount is normalized to a month by flooring it over its plan's interval
     * length (BillingInterval::months()), so a 12-month plan contributes a twelfth of its charge.
     */
    public function mrrMinor(): int
    {
        $total = 0;

        Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->with('plan')
            ->chunkById(500, function (EloquentCollection $subscriptions) use (&$total): void {
                foreach ($subscriptions as $subscription) {

                    $plan = $subscription->getRelationValue('plan');
                    $months = $plan instanceof SubscriptionPlan
                        ? max(1, $plan->intervalEnum()->months())
                        : 1;

                    $total += intdiv($subscription->amountMinor(), $months);
                }
            });

        return $total;
    }

    /** Count of subscriptions currently in the active state. */
    public function activeSubscriberCount(): int
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->count();
    }

    /**
     * Churn in the window: the number of cancellation and expiry transitions recorded on the
     * subscription-change audit log between from and to.
     */
    public function churnCount(CarbonInterface $from, CarbonInterface $to): int
    {
        return SubscriptionChange::query()
            ->whereIn('type', [
                SubscriptionChangeType::Cancellation->value,
                SubscriptionChangeType::Expired->value,
            ])
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    /**
     * Base query for refunds that reached a succeeded, settled state: refunds carry a status enum
     * and a processed_at settlement timestamp.
     *
     * @return Builder<Refund>
     */
    private function refundQuery(): Builder
    {
        return Refund::query()
            ->where('status', RefundStatus::Succeeded->value)
            ->whereNotNull('processed_at');
    }

    /**
     * Resolve an inclusive [from, to] window from ISO strings. A null bound is unbounded (epoch /
     * now). A date-only "to" (no time component) is stretched to the end of that day so the final
     * day is fully counted.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveRange(?string $from, ?string $to): array
    {
        $start = ($from !== null && $from !== '')
            ? CarbonImmutable::parse($from)
            : CarbonImmutable::createFromTimestamp(0);

        if ($to !== null && $to !== '') {
            $end = CarbonImmutable::parse($to);

            if (! str_contains($to, 'T') && ! str_contains($to, ':')) {
                $end = $end->endOfDay();
            }
        } else {
            $end = CarbonImmutable::now();
        }

        return [$start, $end];
    }
}
