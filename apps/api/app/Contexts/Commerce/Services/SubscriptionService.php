<?php

namespace App\Contexts\Commerce\Services;

use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-side queries over a user's subscriptions.
 *
 *  - activeSubscriptionForUser(): the user's current access-granting subscription (if any), verified
 *    against the live clock via Subscription::isActiveNow() so a lapsed-but-not-yet-swept row is not
 *    mistaken for active.
 *  - entitledCourseIds(): the course ids a user is entitled to through their active subscriptions'
 *    plan → product → courses. Read via the plan's product relation only; this context never imports
 *    a Learning model (the entitlement is surfaced cross-context through EntitlementPort).
 *
 * No writes, no gateway I/O.
 */
class SubscriptionService extends BaseService
{
    /** Paginate a user's subscriptions, newest first, with their plan eager-loaded. */
    /**
     * @return LengthAwarePaginator<int, Subscription>
     */
    public function listForUser(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return Subscription::query()
            ->where('user_id', $userId)
            ->with('plan')
            ->latest('id')
            ->paginate(max(1, min($perPage, 100)));
    }

    /** The user's current access-granting subscription, or null when none is active right now. */
    public function activeSubscriptionForUser(int $userId): ?Subscription
    {
        $candidates = Subscription::query()
            ->where('user_id', $userId)
            ->whereIn('status', Subscription::accessGrantingStatusValues())
            ->with('plan')
            ->latest('id')
            ->get();

        foreach ($candidates as $subscription) {
            if ($subscription->isActiveNow()) {
                return $subscription;
            }
        }

        return null;
    }

    /**
     * The course ids a user is entitled to via their active subscriptions' plan → product → courses.
     *
     * @return list<int>
     */
    public function entitledCourseIds(int $userId): array
    {
        $subscriptions = Subscription::query()
            ->where('user_id', $userId)
            ->whereIn('status', Subscription::accessGrantingStatusValues())
            ->with('plan.product')
            ->get();

        $ids = [];

        foreach ($subscriptions as $subscription) {
            if (! $subscription->isActiveNow()) {
                continue;
            }

            $plan = $subscription->getRelationValue('plan');
            if (! $plan instanceof SubscriptionPlan) {
                continue;
            }

            $product = $plan->getRelationValue('product');
            if (! $product instanceof Model) {
                continue;
            }

            // Resolve the product's courses relation without importing a Learning model. When the
            // relation is undefined getRelationValue() returns null, so this stays a safe no-op.
            $courses = $product->getRelationValue('courses');
            if (! is_iterable($courses)) {
                continue;
            }

            foreach ($courses as $course) {
                if ($course instanceof Model) {
                    $ids[(int) $course->getKey()] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }
}
