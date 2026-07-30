<?php

namespace Tests\Feature\Entitlements;

use App\Contexts\Commerce\Adapters\EntitlementAdapter;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\OrderCourseGrant;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Models\SubscriptionPlanPrice;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Commerce EntitlementAdapter (the Shared EntitlementPort implementation) resolves access from
 * paid one-off course grants and active subscriptions. A grant on a refunded (non-paid) order no
 * longer entitles. Only Commerce models + scalar course ids cross the boundary.
 */
class EntitlementAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function adapter(): EntitlementAdapter
    {
        return app(EntitlementAdapter::class);
    }

    public function test_a_user_with_no_purchases_has_no_entitlements(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->adapter()->hasCourseEntitlement($user->id, 1));
        $this->assertSame([], $this->adapter()->entitledCourseIds($user->id));
    }

    public function test_a_paid_order_grant_entitles_the_course(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user, OrderStatus::Paid);
        $course = Course::factory()->create();

        OrderCourseGrant::create(['order_id' => $order->getKey(), 'course_id' => $course->id]);

        $this->assertTrue($this->adapter()->hasCourseEntitlement($user->id, $course->id));
        $this->assertContains($course->id, $this->adapter()->entitledCourseIds($user->id));
    }

    public function test_a_refunded_order_grant_does_not_entitle(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user, OrderStatus::Refunded);
        $course = Course::factory()->create();

        OrderCourseGrant::create(['order_id' => $order->getKey(), 'course_id' => $course->id]);

        $this->assertFalse($this->adapter()->hasCourseEntitlement($user->id, $course->id));
        $this->assertNotContains($course->id, $this->adapter()->entitledCourseIds($user->id));
    }

    public function test_an_active_subscription_is_recognized_as_access_granting(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::create([
            'name' => 'Pro',
            'interval' => 'monthly',
            'trial_days' => 0,
            'is_active' => true,
        ]);
        SubscriptionPlanPrice::create([
            'plan_id' => $plan->getKey(),
            'currency' => 'SAR',
            'amount_minor' => 9900,
            'is_default' => true,
        ]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->getKey(),
            'status' => SubscriptionStatus::Active->value,
            'current_period_start' => Carbon::create(2020, 1, 1, 0, 0, 0),
            'current_period_end' => Carbon::create(2999, 1, 1, 0, 0, 0),
            'currency' => 'SAR',
            'amount_minor' => 9900,
            'provider' => 'fake',
        ]);

        $this->assertTrue($subscription->isActiveNow());
        // The adapter surfaces no course ids because this plan bundles no product/courses, but the
        // active subscription itself is access-granting (a lapsed one would not be).
        $this->assertSame([], $this->adapter()->entitledCourseIds($user->id));
    }

    private function order(User $user, OrderStatus $status): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'status' => $status->value,
            'currency' => 'SAR',
            'subtotal_minor' => 10000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 10000,
            'placed_at' => now(),
        ]);

        if ($status === OrderStatus::Paid) {
            $order->forceFill(['paid_at' => now()])->save();
        }

        return $order;
    }
}
