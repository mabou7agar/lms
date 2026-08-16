<?php

namespace App\Contexts\Commerce\Http\Resources;

use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Admin read model for the commerce analytics summary. Shapes the aggregate array produced by the
 * CommerceAnalyticsService for the dashboard: the resolved range, revenue / refunds / net revenue,
 * order volume and average order value, coupon usage, recurring-revenue (MRR/ARR) and subscriber
 * figures, and churn. Every money field is emitted as an integer in minor units. Read-only shaping —
 * no business logic, no persistence.
 *
 * @property array<string, mixed> $resource
 */
class CommerceAnalyticsResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        $coupon = is_array($data['coupon'] ?? null) ? $data['coupon'] : [];
        $range = is_array($data['range'] ?? null) ? $data['range'] : [];

        return [
            // The money fields below are bare integers in minor units, so the payload has to name the
            // currency they are denominated in — otherwise a client cannot format them at all. The
            // aggregate spans the whole store, so it carries the store's configured currency rather
            // than any single order's.
            'currency' => (string) config('commerce.default_currency', 'SAR'),
            'range' => [
                'from' => $range['from'] ?? null,
                'to' => $range['to'] ?? null,
            ],
            'revenue_minor' => (int) ($data['revenue_minor'] ?? 0),
            'refunds_minor' => (int) ($data['refunds_minor'] ?? 0),
            'net_revenue_minor' => (int) ($data['net_revenue_minor'] ?? 0),
            'orders_count' => (int) ($data['orders_count'] ?? 0),
            'aov_minor' => (int) ($data['aov_minor'] ?? 0),
            'coupon' => [
                'redemptions' => (int) ($coupon['redemptions'] ?? 0),
                'discount_minor' => (int) ($coupon['discount_minor'] ?? 0),
            ],
            'mrr_minor' => (int) ($data['mrr_minor'] ?? 0),
            'arr_minor' => (int) ($data['arr_minor'] ?? 0),
            'active_subscribers' => (int) ($data['active_subscribers'] ?? 0),
            'churn_count' => (int) ($data['churn_count'] ?? 0),
        ];
    }
}
