<?php

namespace Tests\Feature\Commerce\OrganizationSubscriptions;

use App\Contexts\Commerce\Actions\Subscription\CancelSubscriptionAction;
use App\Contexts\Commerce\Actions\Subscription\ChangeOrganizationSeatsAction;
use App\Contexts\Commerce\Actions\Subscription\EnterGraceAction;
use App\Contexts\Commerce\Actions\Subscription\ReactivateSubscriptionAction;
use App\Contexts\Commerce\Actions\Subscription\RenewSubscriptionAction;
use App\Contexts\Commerce\Actions\Subscription\SubscribeOrganizationAction;
use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Events\OrganizationSubscriptionCreated;
use App\Contexts\Commerce\Exceptions\SubscriptionException;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Models\SubscriptionPlanPrice;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use App\Contexts\Commerce\Services\OrganizationSubscriptionService;
use App\Domains\Crm\Exceptions\SeatPoolExhaustedException;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\SeatPool;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Audit\AuditLogger;
use App\Platform\Shared\Seats\Contracts\SeatProvisioningPort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * Organization subscriptions + seats. These assert at the model/service/action boundary and reuse the
 * SHARED subscription lifecycle actions (renew/cancel/grace/reactivate) for the organization
 * subscriber — no second lifecycle engine. Gateway I/O is a deterministic in-memory fake; money is
 * integer minor units.
 */

function orgGateway(bool $succeeds): PaymentGateway
{
    return new class($succeeds) implements PaymentGateway
    {
        public int $chargeCalls = 0;

        public function __construct(public bool $succeeds) {}

        public function charge(ChargeRequest $request): ChargeResult
        {
            $this->chargeCalls++;

            return new ChargeResult(
                'prov_'.($request->idempotencyKey ?? $request->reference),
                $this->succeeds ? 'succeeded' : 'failed',
            );
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

function orgPlan(int $amount = 9900, int $trialDays = 0, string $interval = 'monthly', ?int $productId = null): SubscriptionPlan
{
    $plan = SubscriptionPlan::create([
        'name' => 'Team',
        'product_id' => $productId,
        'interval' => $interval,
        'trial_days' => $trialDays,
        'is_active' => true,
    ]);

    SubscriptionPlanPrice::create([
        'plan_id' => $plan->getKey(),
        'currency' => 'SAR',
        'amount_minor' => $amount,
        'is_default' => true,
    ]);

    return $plan->load('prices');
}

function orgMember(Organization $org, ?User $user = null, string $email = 'e@corp.com'): OrganizationMember
{
    return OrganizationMember::create([
        'organization_id' => $org->id,
        'user_id' => $user?->id,
        'email' => $email,
        'role' => 'member',
        'status' => 'active',
    ]);
}

it('creates an organization subscription and provisions a seat pool sized to the quantity', function () {
    Event::fake([OrganizationSubscriptionCreated::class]);

    $org = Organization::factory()->create();
    $plan = orgPlan(amount: 9900);
    $gateway = orgGateway(true);

    $subscription = (new SubscribeOrganizationAction($gateway, app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, $plan, seats: 5, currency: 'SAR');

    expect($subscription->statusEnum())->toBe(SubscriptionStatus::Active)
        ->and($subscription->isOrganization())->toBeTrue()
        ->and($subscription->organizationId())->toBe($org->id)
        ->and($subscription->getAttribute('user_id'))->toBeNull()
        ->and($subscription->seats())->toBe(5)
        ->and($subscription->amountMinor())->toBe(9900) // flat plan price, NOT per-seat
        ->and($subscription->seatPoolId())->not->toBeNull()
        ->and($gateway->chargeCalls)->toBe(1);

    $pool = SeatPool::findOrFail($subscription->seatPoolId());
    expect($pool->total_seats)->toBe(5)
        ->and($pool->used_seats)->toBe(0)
        ->and($pool->organization_id)->toBe($org->id);

    Event::assertDispatched(OrganizationSubscriptionCreated::class);
});

it('rejects an organization subscription with fewer than one seat', function () {
    $org = Organization::factory()->create();
    $plan = orgPlan();

    expect(fn () => (new SubscribeOrganizationAction(orgGateway(true), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, $plan, seats: 0))
        ->toThrow(SubscriptionException::class);
});

it('is idempotent for an active organization subscription to the same plan', function () {
    $org = Organization::factory()->create();
    $plan = orgPlan();
    $action = new SubscribeOrganizationAction(orgGateway(true), app(AuditLogger::class), app(SeatProvisioningPort::class));

    $first = $action->execute($org->id, $plan, seats: 3, currency: 'SAR');
    $second = $action->execute($org->id, $plan, seats: 3, currency: 'SAR');

    expect($first->getKey())->toBe($second->getKey())
        ->and(Subscription::where('organization_id', $org->id)->count())->toBe(1)
        ->and(SeatPool::where('organization_id', $org->id)->count())->toBe(1); // no duplicate pool
});

it('drops an organization subscription to past_due when the first charge fails', function () {
    $org = Organization::factory()->create();
    $plan = orgPlan();

    try {
        (new SubscribeOrganizationAction(orgGateway(false), app(AuditLogger::class), app(SeatProvisioningPort::class)))
            ->execute($org->id, $plan, seats: 2, currency: 'SAR');
        $this->fail('Expected a SubscriptionException on a declined first charge.');
    } catch (SubscriptionException) {
        // expected
    }

    $subscription = Subscription::where('organization_id', $org->id)->firstOrFail();
    expect($subscription->statusEnum())->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->graceEndsAt())->not->toBeNull();
});

it('reuses the shared renewal action and preserves seat assignments across a renewal', function () {
    $org = Organization::factory()->create();
    $plan = orgPlan(amount: 9900);
    $service = app(OrganizationSubscriptionService::class);

    $subscription = (new SubscribeOrganizationAction(orgGateway(true), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, $plan, seats: 2, currency: 'SAR');

    $member = orgMember($org);
    $service->assignEmployee($subscription, $member->id);
    expect($service->seatUsage($subscription)['used'])->toBe(1);

    // Force the period due, then renew via the SHARED action.
    $oldEnd = Carbon::create(2020, 2, 1, 0, 0, 0);
    $subscription->forceFill([
        'current_period_start' => Carbon::create(2020, 1, 1, 0, 0, 0),
        'current_period_end' => $oldEnd,
    ])->save();

    (new RenewSubscriptionAction(orgGateway(true), app(AuditLogger::class)))->execute($subscription->fresh());

    $fresh = $subscription->fresh();
    expect($fresh->statusEnum())->toBe(SubscriptionStatus::Active)
        ->and($fresh->currentPeriodEnd()->equalTo($oldEnd->copy()->addMonth()))->toBeTrue()
        ->and($fresh->seats())->toBe(2);

    // Assignment survived the renewal untouched.
    $pool = SeatPool::findOrFail($fresh->seatPoolId());
    expect($pool->used_seats)->toBe(1)
        ->and($pool->assignments()->where('member_id', $member->id)->whereNull('revoked_at')->exists())->toBeTrue();
});

it('advances an organization renewal exactly once for a duplicated (idempotent) run', function () {
    $org = Organization::factory()->create();
    $plan = orgPlan(amount: 9900);

    $subscription = (new SubscribeOrganizationAction(orgGateway(true), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, $plan, seats: 2, currency: 'SAR');

    $oldEnd = Carbon::create(2020, 2, 1, 0, 0, 0);
    $subscription->forceFill([
        'current_period_start' => Carbon::create(2020, 1, 1, 0, 0, 0),
        'current_period_end' => $oldEnd,
    ])->save();
    $id = $subscription->getKey();

    // Two STALE instances both loaded at the SAME due period_end simulate the collision (scheduler
    // firing twice / a retry racing the first run) — as in the individual-subscription lifecycle test.
    $a = Subscription::findOrFail($id);
    $b = Subscription::findOrFail($id);

    $action = new RenewSubscriptionAction(orgGateway(true), app(AuditLogger::class));
    $action->execute($a);
    $action->execute($b); // duplicate pass on the same observed period

    $fresh = Subscription::findOrFail($id);
    expect($fresh->currentPeriodEnd()->equalTo($oldEnd->copy()->addMonth()))->toBeTrue();
});

it('assigns employees up to capacity and rejects over-allocation', function () {
    $org = Organization::factory()->create();
    $plan = orgPlan();
    $service = app(OrganizationSubscriptionService::class);

    $subscription = (new SubscribeOrganizationAction(orgGateway(true), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, $plan, seats: 1, currency: 'SAR');

    $a = orgMember($org, email: 'a@corp.com');
    $b = orgMember($org, email: 'b@corp.com');

    $service->assignEmployee($subscription, $a->id);

    expect($service->seatUsage($subscription))->toBe(['purchased' => 1, 'used' => 1, 'available' => 0]);

    expect(fn () => $service->assignEmployee($subscription, $b->id))
        ->toThrow(SeatPoolExhaustedException::class);
});

it('rejects a seat downgrade below the number of assigned employees', function () {
    $org = Organization::factory()->create();
    $plan = orgPlan();
    $service = app(OrganizationSubscriptionService::class);

    $subscription = (new SubscribeOrganizationAction(orgGateway(true), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, $plan, seats: 3, currency: 'SAR');

    $service->assignEmployee($subscription, orgMember($org, email: 'a@corp.com')->id);
    $service->assignEmployee($subscription, orgMember($org, email: 'b@corp.com')->id);

    // 2 assigned; a resize to 1 must be rejected.
    expect(fn () => app(ChangeOrganizationSeatsAction::class)->execute($subscription, 1))
        ->toThrow(SubscriptionException::class);

    // Capacity unchanged by the rejected downgrade.
    expect($subscription->fresh()->seats())->toBe(3)
        ->and(SeatPool::findOrFail($subscription->seatPoolId())->total_seats)->toBe(3);
});

it('allows a seat downgrade at or above the assigned count', function () {
    $org = Organization::factory()->create();
    $plan = orgPlan();
    $service = app(OrganizationSubscriptionService::class);

    $subscription = (new SubscribeOrganizationAction(orgGateway(true), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, $plan, seats: 5, currency: 'SAR');

    $service->assignEmployee($subscription, orgMember($org, email: 'a@corp.com')->id);

    app(ChangeOrganizationSeatsAction::class)->execute($subscription, 2);

    expect($subscription->fresh()->seats())->toBe(2)
        ->and(SeatPool::findOrFail($subscription->seatPoolId())->total_seats)->toBe(2);
});

it('reuses the shared cancel, grace, and reactivate actions for an organization subscription', function () {
    $org = Organization::factory()->create();
    $plan = orgPlan();

    // Immediate cancel (shared action).
    $sub = (new SubscribeOrganizationAction(orgGateway(true), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, $plan, seats: 2, currency: 'SAR');
    (new CancelSubscriptionAction(app(AuditLogger::class)))->execute($sub, atPeriodEnd: false);
    expect($sub->fresh()->statusEnum())->toBe(SubscriptionStatus::Canceled);

    // Grace escalation from past_due (shared action).
    $sub2 = (new SubscribeOrganizationAction(orgGateway(true), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, orgPlan(), seats: 2, currency: 'SAR');
    $sub2->forceFill(['status' => SubscriptionStatus::PastDue->value])->save();
    (new EnterGraceAction(app(AuditLogger::class)))->execute($sub2);
    expect($sub2->fresh()->statusEnum())->toBe(SubscriptionStatus::Grace);

    // Reactivation of a scheduled cancel (shared action).
    $sub3 = (new SubscribeOrganizationAction(orgGateway(true), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, orgPlan(), seats: 2, currency: 'SAR');
    $sub3->forceFill([
        'current_period_end' => Carbon::create(2999, 1, 1, 0, 0, 0),
        'cancel_at_period_end' => true,
    ])->save();
    (new ReactivateSubscriptionAction(app(AuditLogger::class)))->execute($sub3);
    expect($sub3->fresh()->cancelAtPeriodEnd())->toBeFalse()
        ->and($sub3->fresh()->statusEnum())->toBe(SubscriptionStatus::Active);
});

it('releases every seat a deactivated employee holds', function () {
    $org = Organization::factory()->create();
    $service = app(OrganizationSubscriptionService::class);

    $subscription = (new SubscribeOrganizationAction(orgGateway(true), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, orgPlan(), seats: 2, currency: 'SAR');

    $member = orgMember($org);
    $service->assignEmployee($subscription, $member->id);
    expect($service->seatUsage($subscription)['used'])->toBe(1);

    $service->releaseSeatsForMember($member->id);

    expect($service->seatUsage($subscription)['used'])->toBe(0)
        ->and(SeatPool::findOrFail($subscription->seatPoolId())->used_seats)->toBe(0);
});

it('exposes a read-only summary of the organization subscription and seat usage', function () {
    $org = Organization::factory()->create();
    $service = app(OrganizationSubscriptionService::class);

    $subscription = (new SubscribeOrganizationAction(orgGateway(true), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, orgPlan(), seats: 4, currency: 'SAR');
    $service->assignEmployee($subscription, orgMember($org)->id);

    $summary = $service->summary($org->id);

    expect($summary)->not->toBeNull()
        ->and($summary['status'])->toBe(SubscriptionStatus::Active->value)
        ->and($summary['seats'])->toBe(['purchased' => 4, 'used' => 1, 'available' => 3]);

    // No active subscription for an unrelated organization.
    expect($service->summary(Organization::factory()->create()->id))->toBeNull();
});
