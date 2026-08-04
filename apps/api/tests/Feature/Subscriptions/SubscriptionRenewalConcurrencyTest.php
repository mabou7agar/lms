<?php

namespace Tests\Feature\Subscriptions;

use App\Contexts\Commerce\Actions\Subscription\RenewSubscriptionAction;
use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionChange;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Models\SubscriptionPlanPrice;
use App\Contexts\Commerce\Models\SubscriptionRenewalClaim;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * A counting, deterministic non-Stripe fake gateway: it records every charge invocation and, unlike
 * StripeGateway, does NOT honour ChargeRequest::idempotencyKey — so the only thing preventing a
 * double-charge is the DB-enforced renewal claim, exactly the class of adapter this finding targets.
 */
if (! function_exists('renewalConcurrencyGateway')) {
    function renewalConcurrencyGateway(bool $succeeds): PaymentGateway
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

if (! function_exists('renewalConcurrencyPlan')) {
    function renewalConcurrencyPlan(int $amount = 9900): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Pro',
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

        return $plan->load('prices');
    }
}

if (! function_exists('renewalConcurrencyDueSubscription')) {
    /**
     * A subscription whose current period ended on 2020-02-01 (long past) and is therefore due.
     *
     * @param  array<string, mixed>  $overrides
     */
    function renewalConcurrencyDueSubscription(User $user, SubscriptionPlan $plan, array $overrides = []): Subscription
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
}

if (! function_exists('renewalConcurrencyAction')) {
    function renewalConcurrencyAction(PaymentGateway $gateway): RenewSubscriptionAction
    {
        return new RenewSubscriptionAction($gateway, app(AuditLogger::class));
    }
}

test('a period already claimed by a winning worker makes the racing pass skip the charge', function () {
    $user = User::factory()->create();
    $plan = renewalConcurrencyPlan();
    $subscription = renewalConcurrencyDueSubscription($user, $plan);
    $dueStart = Carbon::create(2020, 2, 1, 0, 0, 0);

    // The winning worker's claim already exists for this exact due period.
    SubscriptionRenewalClaim::create([
        'subscription_id' => $subscription->getKey(),
        'period_start' => $dueStart,
    ]);

    $gateway = renewalConcurrencyGateway(true);
    renewalConcurrencyAction($gateway)->execute(Subscription::findOrFail($subscription->getKey()));

    // The losing pass must NOT charge, must NOT advance, must NOT record a renewal.
    expect($gateway->chargeCalls)->toBe(0);

    $fresh = $subscription->fresh();
    expect($fresh->currentPeriodEnd()->equalTo($dueStart))->toBeTrue();
    expect($fresh->statusEnum())->toBe(SubscriptionStatus::Active);

    expect(SubscriptionChange::where('subscription_id', $subscription->getKey())
        ->where('type', SubscriptionChangeType::Renewal->value)->count())->toBe(0);

    // The pre-existing claim is untouched — no duplicate claim was created.
    expect(SubscriptionRenewalClaim::where('subscription_id', $subscription->getKey())->count())->toBe(1);
});

test('two workers racing the same due period charge exactly once and record one renewal', function () {
    $user = User::factory()->create();
    $plan = renewalConcurrencyPlan();
    $subscription = renewalConcurrencyDueSubscription($user, $plan);
    $id = $subscription->getKey();
    $oldEnd = Carbon::create(2020, 2, 1, 0, 0, 0);

    $gateway = renewalConcurrencyGateway(true);
    $action = renewalConcurrencyAction($gateway);

    // Two stale instances both loaded at the same due period_end model the collision. The winner
    // claims + charges + advances; the loser re-reads the advanced period under the lock and bails.
    $winner = Subscription::findOrFail($id);
    $loser = Subscription::findOrFail($id);
    $action->execute($winner);
    $action->execute($loser);

    expect($gateway->chargeCalls)->toBe(1);

    $fresh = Subscription::findOrFail($id);
    expect($fresh->currentPeriodEnd()->equalTo($oldEnd->copy()->addMonth()))->toBeTrue();
    expect($fresh->statusEnum())->toBe(SubscriptionStatus::Active);

    expect(SubscriptionChange::where('subscription_id', $id)
        ->where('type', SubscriptionChangeType::Renewal->value)->count())->toBe(1);

    // Exactly one permanent claim survives for the charged period.
    expect(SubscriptionRenewalClaim::where('subscription_id', $id)->count())->toBe(1);
});

test('a retry after a failed charge can re-attempt and charge the same still-due period', function () {
    $user = User::factory()->create();
    $plan = renewalConcurrencyPlan();
    $subscription = renewalConcurrencyDueSubscription($user, $plan);
    $id = $subscription->getKey();
    $oldEnd = Carbon::create(2020, 2, 1, 0, 0, 0);

    // First attempt: the gateway declines. Period must not advance; claim must be released.
    $failing = renewalConcurrencyGateway(false);
    renewalConcurrencyAction($failing)->execute(Subscription::findOrFail($id));

    expect($failing->chargeCalls)->toBe(1);
    $afterFail = Subscription::findOrFail($id);
    expect($afterFail->statusEnum())->toBe(SubscriptionStatus::PastDue);
    expect($afterFail->currentPeriodEnd()->equalTo($oldEnd))->toBeTrue();
    expect(SubscriptionRenewalClaim::where('subscription_id', $id)->count())->toBe(0);
    expect(SubscriptionChange::where('subscription_id', $id)
        ->where('type', SubscriptionChangeType::Renewal->value)->count())->toBe(0);

    // Retry: the released claim lets the genuine retry re-claim and charge the same period.
    $succeeding = renewalConcurrencyGateway(true);
    renewalConcurrencyAction($succeeding)->execute(Subscription::findOrFail($id));

    expect($succeeding->chargeCalls)->toBe(1);
    $renewed = Subscription::findOrFail($id);
    expect($renewed->statusEnum())->toBe(SubscriptionStatus::Active);
    expect($renewed->currentPeriodEnd()->equalTo($oldEnd->copy()->addMonth()))->toBeTrue();
    expect(SubscriptionChange::where('subscription_id', $id)
        ->where('type', SubscriptionChangeType::Renewal->value)->count())->toBe(1);
    expect(SubscriptionRenewalClaim::where('subscription_id', $id)->count())->toBe(1);
});

test('a not-due subscription is a noop and takes no claim', function () {
    $user = User::factory()->create();
    $plan = renewalConcurrencyPlan();
    $future = Carbon::create(2999, 1, 1, 0, 0, 0);
    $subscription = renewalConcurrencyDueSubscription($user, $plan, [
        'current_period_end' => $future,
    ]);

    $gateway = renewalConcurrencyGateway(true);
    renewalConcurrencyAction($gateway)->execute($subscription);

    expect($gateway->chargeCalls)->toBe(0);
    expect($subscription->fresh()->currentPeriodEnd()->equalTo($future))->toBeTrue();
    expect(SubscriptionRenewalClaim::where('subscription_id', $subscription->getKey())->count())->toBe(0);
});

test('the renewal claim unique constraint is enforced at the database', function () {
    $user = User::factory()->create();
    $plan = renewalConcurrencyPlan();
    $subscription = renewalConcurrencyDueSubscription($user, $plan);
    $periodStart = Carbon::create(2020, 2, 1, 0, 0, 0);

    SubscriptionRenewalClaim::create([
        'subscription_id' => $subscription->getKey(),
        'period_start' => $periodStart,
    ]);

    // Wrap the violating INSERT in its own transaction so, on Postgres, the failure rolls back to a
    // SAVEPOINT rather than aborting RefreshDatabase's wrapping transaction (which would poison every
    // subsequent query in this test with SQLSTATE 25P02). The assertion — the DB rejects the duplicate
    // — is unchanged.
    $threw = false;
    try {
        DB::transaction(function () use ($subscription, $periodStart): void {
            SubscriptionRenewalClaim::create([
                'subscription_id' => $subscription->getKey(),
                'period_start' => $periodStart,
            ]);
        });
    } catch (QueryException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    expect(SubscriptionRenewalClaim::where('subscription_id', $subscription->getKey())->count())->toBe(1);
});
