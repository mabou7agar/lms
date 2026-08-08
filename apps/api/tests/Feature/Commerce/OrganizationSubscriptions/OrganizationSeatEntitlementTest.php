<?php

namespace Tests\Feature\Commerce\OrganizationSubscriptions;

use App\Contexts\Commerce\Actions\Subscription\SubscribeAction;
use App\Contexts\Commerce\Actions\Subscription\SubscribeOrganizationAction;
use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Models\Product;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Models\SubscriptionPlanPrice;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use App\Contexts\Commerce\Services\EntitlementService;
use App\Contexts\Commerce\Services\OrganizationSubscriptionService;
use App\Contexts\Commerce\Services\SubscriptionService;
use App\Domains\Catalog\Models\Course;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Audit\AuditLogger;
use App\Platform\Shared\Seats\Contracts\SeatProvisioningPort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
 * Seat-based entitlement propagation, and a regression guard that individual (user) subscriptions and
 * their entitlements are completely unaffected by the organization/seat additions.
 */

function seatGateway(): PaymentGateway
{
    return new class implements PaymentGateway
    {
        public function charge(ChargeRequest $request): ChargeResult
        {
            return new ChargeResult('prov_'.($request->idempotencyKey ?? $request->reference), 'succeeded');
        }

        public function refund(RefundRequest $request): RefundResult
        {
            return new RefundResult($request->providerReference, 'succeeded');
        }

        public function parseWebhook(string $payload, ?string $signature): WebhookEvent
        {
            return new WebhookEvent('evt', 'payment.succeeded', 'ref');
        }
    };
}

/** A plan bundling one published course through a product, priced in SAR. Returns [plan, courseId]. */
function seatPlanWithCourse(int $amount = 9900): array
{
    $course = Course::factory()->published()->create();
    $product = Product::factory()->create();
    $product->courses()->sync([$course->id]);

    $plan = SubscriptionPlan::create([
        'name' => 'Team',
        'product_id' => $product->id,
        'interval' => 'monthly',
        'trial_days' => 0,
        'is_active' => true,
    ]);
    SubscriptionPlanPrice::create([
        'plan_id' => $plan->getKey(),
        'currency' => 'SAR',
        'amount_minor' => $amount,
        'is_default' => true,
    ]);

    return [$plan->load('prices'), (int) $course->id];
}

function seatMember(Organization $org, User $user): OrganizationMember
{
    return OrganizationMember::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'email' => $user->email ?? 'seat@corp.com',
        'role' => 'member',
        'status' => 'active',
    ]);
}

it('propagates entitlements to a seated employee and revokes on release', function () {
    [$plan, $courseId] = seatPlanWithCourse();
    $org = Organization::factory()->create();
    $employee = User::factory()->create();

    $subscription = (new SubscribeOrganizationAction(seatGateway(), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, $plan, seats: 3, currency: 'SAR');

    $member = seatMember($org, $employee);
    $entitlements = app(EntitlementService::class);

    // Not seated yet: no entitlement.
    expect($entitlements->hasCourseEntitlement($employee->id, $courseId))->toBeFalse();

    app(OrganizationSubscriptionService::class)->assignEmployee($subscription, $member->id);

    // Seated: entitlement gained.
    expect($entitlements->hasCourseEntitlement($employee->id, $courseId))->toBeTrue()
        ->and($entitlements->entitledCourseIds($employee->id))->toContain($courseId);

    // Released: entitlement revoked.
    app(OrganizationSubscriptionService::class)->unassignEmployee($subscription, $member->id);

    expect($entitlements->hasCourseEntitlement($employee->id, $courseId))->toBeFalse()
        ->and($entitlements->entitledCourseIds($employee->id))->not->toContain($courseId);
});

it('revokes seat entitlement when the organization subscription lapses (expired)', function () {
    [$plan, $courseId] = seatPlanWithCourse();
    $org = Organization::factory()->create();
    $employee = User::factory()->create();

    $subscription = (new SubscribeOrganizationAction(seatGateway(), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, $plan, seats: 2, currency: 'SAR');
    app(OrganizationSubscriptionService::class)->assignEmployee($subscription, seatMember($org, $employee)->id);

    $entitlements = app(EntitlementService::class);
    expect($entitlements->hasCourseEntitlement($employee->id, $courseId))->toBeTrue();

    // Subscription expires (no longer grants access) — seat access follows the subscription.
    $subscription->forceFill([
        'status' => SubscriptionStatus::Expired->value,
        'current_period_end' => Carbon::create(2020, 1, 1, 0, 0, 0),
    ])->save();

    expect($entitlements->hasCourseEntitlement($employee->id, $courseId))->toBeFalse();
});

it('leaves individual user subscriptions and their entitlements unaffected (regression)', function () {
    // An individual user subscription to its own plan/course — the pre-existing path.
    [$userPlan, $userCourseId] = seatPlanWithCourse();
    $user = User::factory()->create();

    $userSub = (new SubscribeAction(seatGateway(), app(AuditLogger::class)))
        ->execute($user->id, $userPlan, 'SAR');

    expect($userSub->statusEnum())->toBe(SubscriptionStatus::Active)
        ->and($userSub->isOrganization())->toBeFalse()
        ->and($userSub->getAttribute('organization_id'))->toBeNull()
        ->and($userSub->userId())->toBe($user->id);

    // The user-only read side still resolves exactly the individual subscription.
    $resolved = app(SubscriptionService::class)->activeSubscriptionForUser($user->id);
    expect($resolved?->getKey())->toBe($userSub->getKey());

    $entitlements = app(EntitlementService::class);
    expect($entitlements->hasCourseEntitlement($user->id, $userCourseId))->toBeTrue();

    // A separate organization subscription seating a DIFFERENT employee must not leak into the
    // individual user's entitlements, and the individual course must not leak to the employee.
    [$orgPlanModel, $orgCourseId] = seatPlanWithCourse();
    $org = Organization::factory()->create();
    $employee = User::factory()->create();

    $orgSub = (new SubscribeOrganizationAction(seatGateway(), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, $orgPlanModel, seats: 2, currency: 'SAR');
    app(OrganizationSubscriptionService::class)->assignEmployee($orgSub, seatMember($org, $employee)->id);

    expect($entitlements->hasCourseEntitlement($user->id, $orgCourseId))->toBeFalse()
        ->and($entitlements->hasCourseEntitlement($employee->id, $userCourseId))->toBeFalse();

    // The org subscription is not visible through the user-only resolver for the seated employee
    // (it has no individual subscription of its own).
    expect(app(SubscriptionService::class)->activeSubscriptionForUser($employee->id))->toBeNull();

    // And the individual user's row is untouched: still user-owned, no organization/seat fields.
    $freshUserSub = Subscription::findOrFail($userSub->getKey());
    expect($freshUserSub->getAttribute('organization_id'))->toBeNull()
        ->and($freshUserSub->getAttribute('seat_pool_id'))->toBeNull()
        ->and($freshUserSub->getAttribute('seats'))->toBeNull();
});
