<?php

namespace App\Contexts\Commerce\Services;

use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Models\OrderCourseGrant;
use App\Contexts\Commerce\Models\Subscription;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-side entitlement resolver. Answers "which courses may this user access, and why" by unioning
 * the two sources of access in Commerce:
 *
 *   (a) paid one-off purchases — an OrderCourseGrant that hangs off an order whose status is Paid
 *       (a refunded/cancelled order is excluded because its status is no longer Paid); and
 *   (b) active subscriptions — a Subscription whose status grantsAccess() and whose current period
 *       has not yet ended, resolved through plan -> product -> courses to the bundled course ids.
 *
 * Everything here is Commerce models + scalars only; no Learning models are imported. The concrete
 * EntitlementAdapter (the Shared EntitlementPort implementation) stays thin and delegates to this
 * service so richer queries have one home.
 */
class EntitlementService extends BaseService
{
    /**
     * Every course id the user is currently entitled to, from both sources, de-duplicated and
     * sorted ascending for a stable response.
     *
     * @return list<int>
     */
    public function entitledCourseIds(int $userId): array
    {
        return $this->oneOffGrantCourseIds($userId)
            ->merge($this->subscriptionCourseIds($userId))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Whether the user is entitled to a specific course from any source. Existence-only queries —
     * no rows are hydrated.
     */
    public function hasCourseEntitlement(int $userId, int $courseId): bool
    {
        return $this->hasOneOffGrant($userId, $courseId)
            || $this->hasActiveSubscriptionForCourse($userId, $courseId);
    }

    /**
     * Course ids granted by paid one-off purchases: an OrderCourseGrant on an order the user owns
     * whose status is Paid.
     *
     * @return Collection<int, int>
     */
    private function oneOffGrantCourseIds(int $userId): Collection
    {
        return OrderCourseGrant::query()
            ->whereHas('order', fn ($query) => $query
                ->where('user_id', $userId)
                ->whereIn('status', $this->paidOrderStatuses()))
            ->pluck('course_id')
            ->map(fn ($id): int => (int) $id);
    }

    private function hasOneOffGrant(int $userId, int $courseId): bool
    {
        return OrderCourseGrant::query()
            ->where('course_id', $courseId)
            ->whereHas('order', fn ($query) => $query
                ->where('user_id', $userId)
                ->whereIn('status', $this->paidOrderStatuses()))
            ->exists();
    }

    /**
     * Course ids bundled by the user's currently-active subscriptions, resolved through
     * plan -> product -> courses.
     *
     * @return Collection<int, int>
     */
    private function subscriptionCourseIds(int $userId): Collection
    {
        return $this->activeSubscriptions($userId)
            ->with('plan.product.courses')
            ->get()
            ->flatMap(function (Subscription $subscription): array {
                $courses = $subscription->plan?->product?->courses;

                if (! $courses instanceof Collection) {
                    return [];
                }

                return $courses
                    ->map(fn ($course): int => (int) $course->getKey())
                    ->all();
            });
    }

    private function hasActiveSubscriptionForCourse(int $userId, int $courseId): bool
    {
        return $this->activeSubscriptions($userId)
            ->whereHas('plan.product.courses', fn ($query) => $query->whereKey($courseId))
            ->exists();
    }

    /**
     * Base query for the user's access-granting subscriptions: a status that grantsAccess() and a
     * current period that has not yet lapsed.
     *
     * @return Builder<Subscription>
     */
    private function activeSubscriptions(int $userId): Builder
    {
        return Subscription::query()
            ->where('user_id', $userId)
            ->whereIn('status', $this->accessGrantingSubscriptionStatuses())
            ->where('current_period_end', '>', now());
    }

    /**
     * The order statuses that represent a settled, non-reversed purchase. Kept as a set so the
     * fulfilment vocabulary can grow without touching the callers.
     *
     * @return list<string>
     */
    private function paidOrderStatuses(): array
    {
        return [OrderStatus::Paid->value];
    }

    /**
     * The subscription status values whose enum grantsAccess() is true, projected to their backing
     * strings for a whereIn on the column.
     *
     * @return list<string>
     */
    private function accessGrantingSubscriptionStatuses(): array
    {
        return array_values(array_map(
            fn (SubscriptionStatus $status): string => $status->value,
            array_filter(
                SubscriptionStatus::cases(),
                fn (SubscriptionStatus $status): bool => $status->grantsAccess(),
            ),
        ));
    }
}
