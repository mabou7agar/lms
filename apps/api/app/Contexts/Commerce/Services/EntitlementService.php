<?php

namespace App\Contexts\Commerce\Services;

use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Models\OrderCourseGrant;
use App\Contexts\Commerce\Models\Product;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Platform\Shared\Seats\Contracts\SeatProvisioningPort;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Read-side entitlement resolver. Answers "which courses may this user access, and why" by unioning
 * the three sources of access in Commerce:
 *
 *   (a) paid one-off purchases — an OrderCourseGrant that hangs off an order whose status is Paid
 *       (a refunded/cancelled order is excluded because its status is no longer Paid);
 *   (b) active individual subscriptions — a Subscription the user owns (user_id) whose status
 *       grantsAccess() and whose current period has not yet ended, resolved through
 *       plan -> product -> courses to the bundled course ids; and
 *   (c) organization seat entitlements — a Subscription owned by an ORGANIZATION on whose seat pool
 *       the user currently holds an ACTIVE seat (user -> organization_members -> seat_assignments ->
 *       seat_pool_id -> subscription). Releasing the seat (or the subscription lapsing) revokes it,
 *       because both are re-evaluated on every read.
 *
 * Sources (a)/(b) are Commerce models + scalars only. Source (c) reaches the CRM seat tables through
 * the Shared SeatProvisioningPort (the single Commerce->CRM seam), which returns only scalar pool ids
 * — so no Learning model and no CRM Eloquent class is imported here directly.
 *
 * TENANCY (T1, later): source (c) spans tenant-owned CRM tables; the pool-id lookup behind the
 * SeatProvisioningPort and the seat-pool subscription query below must be tenant-scoped when tenant
 * scoping lands.
 */
class EntitlementService extends BaseService
{
    public function __construct(
        private readonly SeatProvisioningPort $seats,
    ) {}

    /**
     * Every course id the user is currently entitled to, from all sources, de-duplicated and sorted
     * ascending for a stable response.
     *
     * @return list<int>
     */
    public function entitledCourseIds(int $userId): array
    {
        return $this->oneOffGrantCourseIds($userId)
            ->merge($this->subscriptionCourseIds($userId))
            ->merge($this->seatEntitledCourseIds($userId))
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
            || $this->hasActiveSubscriptionForCourse($userId, $courseId)
            || $this->hasSeatEntitlementForCourse($userId, $courseId);
    }

    /**
     * Whether an ACTIVE product sells this course, on its own or inside a bundle.
     *
     * Draft and archived products are ignored: a course whose product is still being prepared is not
     * yet on sale, so it must not be locked away from the payment-free path in the meantime.
     */
    public function isCoursePurchasable(int $courseId): bool
    {
        return Product::query()
            ->active()
            ->whereHas('courses', fn (Builder $q): Builder => $q->whereKey($courseId))
            ->exists();
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
     * Course ids the user is entitled to as a SEATED EMPLOYEE: the organization subscriptions whose
     * seat pool the user currently holds an active seat on, that are access-granting and live now,
     * resolved through plan -> product -> courses.
     *
     * @return Collection<int, int>
     */
    private function seatEntitledCourseIds(int $userId): Collection
    {
        $ids = [];

        foreach ($this->activeSeatSubscriptions($userId) as $subscription) {
            foreach ($this->courseIdsForSubscription($subscription) as $courseId) {
                $ids[$courseId] = $courseId;
            }
        }

        return new Collection(array_values($ids));
    }

    private function hasSeatEntitlementForCourse(int $userId, int $courseId): bool
    {
        foreach ($this->activeSeatSubscriptions($userId) as $subscription) {
            if (in_array($courseId, $this->courseIdsForSubscription($subscription), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The organization subscriptions the user is seated on right now: bound to a seat pool the user
     * holds an active seat in, access-granting, and live per the wall clock (isActiveNow) — so a
     * canceled-but-not-elapsed org subscription still grants access while an expired one does not.
     *
     * @return Collection<int, Subscription>
     */
    private function activeSeatSubscriptions(int $userId): Collection
    {
        $poolIds = $this->seats->activeSeatPoolIdsForUser($userId);

        if ($poolIds === []) {
            return new Collection;
        }

        return Subscription::query()
            ->whereIn('seat_pool_id', $poolIds)
            ->whereIn('status', $this->accessGrantingSubscriptionStatuses())
            ->with('plan.product.courses')
            ->get()
            ->filter(fn (Subscription $subscription): bool => $subscription->isActiveNow())
            ->values();
    }

    /**
     * Course ids bundled by a subscription's plan -> product -> courses, resolved without importing a
     * Learning model (the product's courses relation is read generically).
     *
     * @return list<int>
     */
    private function courseIdsForSubscription(Subscription $subscription): array
    {
        $plan = $subscription->getRelationValue('plan');
        if (! $plan instanceof SubscriptionPlan) {
            return [];
        }

        $product = $plan->getRelationValue('product');
        if (! $product instanceof Model) {
            return [];
        }

        $courses = $product->getRelationValue('courses');
        if (! is_iterable($courses)) {
            return [];
        }

        $ids = [];
        foreach ($courses as $course) {
            if ($course instanceof Model) {
                $ids[] = (int) $course->getKey();
            }
        }

        return $ids;
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
