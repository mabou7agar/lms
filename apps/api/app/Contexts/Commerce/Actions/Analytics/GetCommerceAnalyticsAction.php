<?php

namespace App\Contexts\Commerce\Actions\Analytics;

use App\Contexts\Commerce\Services\Analytics\CommerceAnalyticsService;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Read action for the admin commerce analytics dashboard. Thin orchestration only: it hands the
 * requested ISO [from, to] window to the CommerceAnalyticsService and returns the aggregate summary
 * as-is. No writes, no gateway I/O, no transaction — every figure is an integer in minor units.
 */
class GetCommerceAnalyticsAction extends BaseAction
{
    public function __construct(
        private readonly CommerceAnalyticsService $analytics,
    ) {}

    /**
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
    public function execute(?string $from = null, ?string $to = null): array
    {
        return $this->analytics->summary($from, $to);
    }
}
