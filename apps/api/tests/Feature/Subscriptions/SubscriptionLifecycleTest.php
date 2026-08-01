<?php

namespace Tests\Feature\Subscriptions;

use App\Contexts\Commerce\Actions\Subscription\CancelSubscriptionAction;
use App\Contexts\Commerce\Actions\Subscription\EnterGraceAction;
use App\Contexts\Commerce\Actions\Subscription\ExpireSubscriptionsAction;
use App\Contexts\Commerce\Actions\Subscription\ReactivateSubscriptionAction;
use App\Contexts\Commerce\Actions\Subscription\RenewSubscriptionAction;
use App\Contexts\Commerce\Actions\Subscription\SubscribeAction;
use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Events\SubscriptionCanceled;
use App\Contexts\Commerce\Events\SubscriptionCreated;
use App\Contexts\Commerce\Events\SubscriptionEnteredGrace;
use App\Contexts\Commerce\Events\SubscriptionExpired;
use App\Contexts\Commerce\Events\SubscriptionRenewed;
use App\Contexts\Commerce\Exceptions\SubscriptionException;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Models\SubscriptionPlanPrice;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use App\Contexts\Commerce\Services\SubscriptionService;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Subscription state machine: subscribe (active + first charge, or trial without charge), renewal
 * advancing the period, cancellation (scheduled vs immediate), reactivation, grace escalation, and
 * expiry. Gateway I/O is a deterministic in-memory fake; money is integer minor units.
 */
class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscribe_activates_and_charges_first_period(): void
    {
        Event::fake([SubscriptionCreated::class]);

        $user = User::factory()->create();
        $plan = $this->createPlan(amount: 9900);
        $gateway = $this->gateway(true);

        $subscription = (new SubscribeAction($gateway, app(AuditLogger::class)))
            ->execute($user->id, $plan, 'SAR');

        $this->assertSame(SubscriptionStatus::Active, $subscription->statusEnum());
        $this->assertSame(9900, $subscription->amountMinor());
        $this->assertNotNull($subscription->getAttribute('provider_reference'));
        $this->assertSame(1, $gateway->chargeCalls);

        $this->assertDatabaseHas('subscription_changes', [
            'subscription_id' => $subscription->getKey(),
            'type' => SubscriptionChangeType::Created->value,
        ]);

        Event::assertDispatched(SubscriptionCreated::class);
    }

    public function test_trial_plan_starts_trialing_without_charging(): void
    {
        Event::fake([SubscriptionCreated::class]);

        $user = User::factory()->create();
        $plan = $this->createPlan(amount: 9900, trialDays: 7);
        $gateway = $this->gateway(true);

        $subscription = (new SubscribeAction($gateway, app(AuditLogger::class)))
            ->execute($user->id, $plan, 'SAR');

        $this->assertSame(SubscriptionStatus::Trialing, $subscription->statusEnum());
        $this->assertNotNull($subscription->trialEndsAt());
        $this->assertSame(0, $gateway->chargeCalls, 'A trial must not charge on subscribe.');
    }

    public function test_subscribe_is_idempotent_for_an_active_subscription(): void
    {
        Event::fake([SubscriptionCreated::class]);

        $user = User::factory()->create();
        $plan = $this->createPlan(amount: 9900);
        $action = new SubscribeAction($this->gateway(true), app(AuditLogger::class));

        $first = $action->execute($user->id, $plan, 'SAR');
        $second = $action->execute($user->id, $plan, 'SAR');

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, Subscription::where('user_id', $user->id)->count());
    }

    public function test_failed_first_charge_drops_subscription_to_past_due(): void
    {
        Event::fake([SubscriptionCreated::class]);

        $user = User::factory()->create();
        $plan = $this->createPlan(amount: 9900);
        $action = new SubscribeAction($this->gateway(false), app(AuditLogger::class));

        try {
            $action->execute($user->id, $plan, 'SAR');
            $this->fail('Expected a SubscriptionException on a declined first charge.');
        } catch (SubscriptionException) {
            // expected
        }

        $subscription = Subscription::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->statusEnum());
        $this->assertNotNull($subscription->graceEndsAt());
    }

    public function test_renewal_advances_the_period_on_success(): void
    {
        Event::fake([SubscriptionRenewed::class]);

        $user = User::factory()->create();
        $plan = $this->createPlan(amount: 9900);
        $oldEnd = Carbon::create(2020, 2, 1, 0, 0, 0);

        $subscription = $this->createSubscription($user, $plan, [
            'status' => SubscriptionStatus::Active->value,
            'current_period_start' => Carbon::create(2020, 1, 1, 0, 0, 0),
            'current_period_end' => $oldEnd,
        ]);

        (new RenewSubscriptionAction($this->gateway(true), app(AuditLogger::class)))
            ->execute($subscription);

        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::Active, $fresh->statusEnum());
        $this->assertTrue($fresh->currentPeriodStart()->equalTo($oldEnd));
        $this->assertTrue($fresh->currentPeriodEnd()->equalTo($oldEnd->copy()->addMonth()));

        $this->assertDatabaseHas('subscription_changes', [
            'subscription_id' => $subscription->getKey(),
            'type' => SubscriptionChangeType::Renewal->value,
        ]);

        Event::assertDispatched(SubscriptionRenewed::class);
    }

    public function test_renewal_is_a_noop_when_not_due(): void
    {
        Event::fake([SubscriptionRenewed::class]);

        $user = User::factory()->create();
        $plan = $this->createPlan(amount: 9900);
        $future = Carbon::create(2999, 1, 1, 0, 0, 0);

        $subscription = $this->createSubscription($user, $plan, [
            'status' => SubscriptionStatus::Active->value,
            'current_period_end' => $future,
        ]);

        (new RenewSubscriptionAction($this->gateway(true), app(AuditLogger::class)))
            ->execute($subscription);

        $this->assertTrue($subscription->fresh()->currentPeriodEnd()->equalTo($future));
        Event::assertNotDispatched(SubscriptionRenewed::class);
    }

    /**
     * Renewal-collision guard: two invocations racing on the SAME due period (e.g. the scheduler
     * firing twice, or a retry racing the first run) must not corrupt billing. Both derive the
     * gateway idempotency key from the period start, so production dedups the charge; and both
     * advance to the same deterministic target, so the period moves exactly one interval — never two.
     */
    public function test_duplicate_renewal_for_the_same_period_advances_once_with_one_key(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlan(amount: 9900);
        $oldEnd = Carbon::create(2020, 2, 1, 0, 0, 0);

        $subscription = $this->createSubscription($user, $plan, [
            'status' => SubscriptionStatus::Active->value,
            'current_period_start' => Carbon::create(2020, 1, 1, 0, 0, 0),
            'current_period_end' => $oldEnd,
        ]);
        $id = $subscription->getKey();

        $gateway = $this->gateway(true);
        $action = new RenewSubscriptionAction($gateway, app(AuditLogger::class));

        // Two STALE instances both loaded at the same due period_end simulate the collision.
        $a = Subscription::findOrFail($id);
        $b = Subscription::findOrFail($id);
        $action->execute($a);
        $action->execute($b);

        // Period advanced by exactly ONE interval — no double advance / no billing-period corruption.
        $fresh = Subscription::findOrFail($id);
        $this->assertTrue($fresh->currentPeriodEnd()->equalTo($oldEnd->copy()->addMonth()));
        // The idempotency key is derived from the period start (identical for both racers), so the
        // real gateway collapses them to a single charge.
        $this->assertStringContainsString(':r20200201', (string) $fresh->getAttribute('provider_reference'));
    }

    public function test_cancel_at_period_end_flags_without_terminating(): void
    {
        Event::fake([SubscriptionCanceled::class]);

        $user = User::factory()->create();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription($user, $plan, [
            'status' => SubscriptionStatus::Active->value,
            'current_period_end' => Carbon::create(2999, 1, 1, 0, 0, 0),
        ]);

        (new CancelSubscriptionAction(app(AuditLogger::class)))->execute($subscription, true);

        $fresh = $subscription->fresh();
        $this->assertTrue($fresh->cancelAtPeriodEnd());
        $this->assertSame(SubscriptionStatus::Active, $fresh->statusEnum());
        Event::assertNotDispatched(SubscriptionCanceled::class);
    }

    public function test_immediate_cancel_terminates_and_emits_event(): void
    {
        Event::fake([SubscriptionCanceled::class]);

        $user = User::factory()->create();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription($user, $plan, [
            'status' => SubscriptionStatus::Active->value,
            'current_period_end' => Carbon::create(2999, 1, 1, 0, 0, 0),
        ]);

        (new CancelSubscriptionAction(app(AuditLogger::class)))->execute($subscription, false);

        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::Canceled, $fresh->statusEnum());
        $this->assertNotNull($fresh->getAttribute('canceled_at'));
        Event::assertDispatched(SubscriptionCanceled::class);
    }

    public function test_reactivate_clears_a_scheduled_cancellation(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription($user, $plan, [
            'status' => SubscriptionStatus::Active->value,
            'current_period_end' => Carbon::create(2999, 1, 1, 0, 0, 0),
            'cancel_at_period_end' => true,
        ]);

        (new ReactivateSubscriptionAction(app(AuditLogger::class)))->execute($subscription);

        $fresh = $subscription->fresh();
        $this->assertFalse($fresh->cancelAtPeriodEnd());
        $this->assertSame(SubscriptionStatus::Active, $fresh->statusEnum());
        $this->assertDatabaseHas('subscription_changes', [
            'subscription_id' => $subscription->getKey(),
            'type' => SubscriptionChangeType::Reactivation->value,
        ]);
    }

    public function test_enter_grace_moves_past_due_into_grace(): void
    {
        Event::fake([SubscriptionEnteredGrace::class]);

        $user = User::factory()->create();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription($user, $plan, [
            'status' => SubscriptionStatus::PastDue->value,
            'current_period_end' => Carbon::create(2020, 2, 1, 0, 0, 0),
        ]);

        (new EnterGraceAction(app(AuditLogger::class)))->execute($subscription);

        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::Grace, $fresh->statusEnum());
        $this->assertTrue($fresh->graceEndsAt()->isFuture());
        Event::assertDispatched(SubscriptionEnteredGrace::class);
    }

    public function test_expire_lapses_a_subscription_past_its_grace_window(): void
    {
        Event::fake([SubscriptionExpired::class]);

        $user = User::factory()->create();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription($user, $plan, [
            'status' => SubscriptionStatus::Grace->value,
            'current_period_end' => Carbon::create(2020, 2, 1, 0, 0, 0),
            'grace_ends_at' => Carbon::create(2020, 2, 5, 0, 0, 0),
        ]);

        $action = new ExpireSubscriptionsAction(app(AuditLogger::class));

        $this->assertTrue($action->expire($subscription));
        $this->assertSame(SubscriptionStatus::Expired, $subscription->fresh()->statusEnum());
        Event::assertDispatched(SubscriptionExpired::class);
    }

    public function test_expire_batch_counts_only_lapsed_subscriptions(): void
    {
        Event::fake([SubscriptionExpired::class]);

        $user = User::factory()->create();
        $plan = $this->createPlan();

        $this->createSubscription($user, $plan, [
            'status' => SubscriptionStatus::Grace->value,
            'grace_ends_at' => Carbon::create(2020, 2, 5, 0, 0, 0),
        ]);
        $this->createSubscription($user, $plan, [
            'status' => SubscriptionStatus::Grace->value,
            'grace_ends_at' => Carbon::create(2999, 1, 1, 0, 0, 0),
        ]);

        $expired = (new ExpireSubscriptionsAction(app(AuditLogger::class)))->execute();

        $this->assertSame(1, $expired);
    }

    public function test_service_resolves_only_a_live_active_subscription(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlan();

        $this->createSubscription($user, $plan, [
            'status' => SubscriptionStatus::Expired->value,
            'current_period_end' => Carbon::create(2020, 2, 1, 0, 0, 0),
        ]);
        $active = $this->createSubscription($user, $plan, [
            'status' => SubscriptionStatus::Active->value,
            'current_period_end' => Carbon::create(2999, 1, 1, 0, 0, 0),
        ]);

        $resolved = app(SubscriptionService::class)->activeSubscriptionForUser($user->id);

        $this->assertNotNull($resolved);
        $this->assertSame($active->getKey(), $resolved->getKey());
    }

    private function createPlan(int $amount = 9900, int $trialDays = 0, string $interval = 'monthly'): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Pro',
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSubscription(User $user, SubscriptionPlan $plan, array $overrides = []): Subscription
    {
        return Subscription::create(array_replace([
            'user_id' => $user->id,
            'plan_id' => $plan->getKey(),
            'status' => SubscriptionStatus::Active->value,
            'current_period_start' => Carbon::create(2020, 1, 1, 0, 0, 0),
            'current_period_end' => Carbon::create(2020, 2, 1, 0, 0, 0),
            'currency' => 'SAR',
            'amount_minor' => 9900,
            'provider' => 'fake',
        ], $overrides));
    }

    private function gateway(bool $succeeds): PaymentGateway
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
}
