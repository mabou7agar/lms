<?php

use App\Contexts\Commerce\Actions\Subscription\CancelSubscriptionAction;
use App\Contexts\Commerce\Actions\Subscription\ReactivateSubscriptionAction;
use App\Contexts\Commerce\Actions\Subscription\RenewSubscriptionAction;
use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Exceptions\SubscriptionException;
use App\Contexts\Commerce\Filament\Resources\SubscriptionResource;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Models\SubscriptionPlanPrice;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(Carbon::create(2021, 6, 15, 12, 0, 0)));
afterEach(fn () => Carbon::setTestNow());

function detailPlan(int $amount = 9900, string $interval = 'monthly'): SubscriptionPlan
{
    $plan = SubscriptionPlan::create([
        'name' => 'Pro',
        'interval' => $interval,
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

/** @param array<string, mixed> $overrides */
function detailSubscription(array $overrides = []): Subscription
{
    $user = User::factory()->create();
    $plan = detailPlan();

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

function detailGateway(bool $succeeds): PaymentGateway
{
    return new class($succeeds) implements PaymentGateway
    {
        public function __construct(public bool $succeeds) {}

        public function charge(ChargeRequest $request): ChargeResult
        {
            return new ChargeResult('prov_'.($request->idempotencyKey ?? $request->reference), $this->succeeds ? 'succeeded' : 'failed');
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

it('cancel-at-period-end delegates to the domain action without terminating', function () {
    $subscription = detailSubscription(['current_period_end' => Carbon::create(2999, 1, 1, 0, 0, 0)]);

    app(CancelSubscriptionAction::class)->execute($subscription, atPeriodEnd: true);

    $fresh = $subscription->fresh();
    expect($fresh->cancelAtPeriodEnd())->toBeTrue();
    expect($fresh->statusEnum())->toBe(SubscriptionStatus::Active);
    $this->assertDatabaseHas('subscription_changes', [
        'subscription_id' => $subscription->getKey(),
        'type' => SubscriptionChangeType::Cancellation->value,
    ]);
});

it('protects an invalid transition via the action guard', function () {
    // Reactivating an expired subscription is not a transition the state machine allows.
    $subscription = detailSubscription([
        'status' => SubscriptionStatus::Expired->value,
        'current_period_end' => Carbon::create(2020, 2, 1, 0, 0, 0),
    ]);

    expect(fn () => app(ReactivateSubscriptionAction::class)->execute($subscription))
        ->toThrow(SubscriptionException::class);
});

it('supports cancel then reactivate', function () {
    $subscription = detailSubscription(['current_period_end' => Carbon::create(2999, 1, 1, 0, 0, 0)]);

    app(CancelSubscriptionAction::class)->execute($subscription, atPeriodEnd: true);
    app(ReactivateSubscriptionAction::class)->execute($subscription->fresh());

    $fresh = $subscription->fresh();
    expect($fresh->cancelAtPeriodEnd())->toBeFalse();
    expect($fresh->statusEnum())->toBe(SubscriptionStatus::Active);
    $this->assertDatabaseHas('subscription_changes', [
        'subscription_id' => $subscription->getKey(),
        'type' => SubscriptionChangeType::Reactivation->value,
    ]);
});

it('makes a failed renewal visible as past_due', function () {
    $subscription = detailSubscription([
        'status' => SubscriptionStatus::Active->value,
        'current_period_end' => Carbon::create(2020, 2, 1, 0, 0, 0),
    ]);

    (new RenewSubscriptionAction(detailGateway(false), app(AuditLogger::class)))->execute($subscription);

    expect($subscription->fresh()->statusEnum())->toBe(SubscriptionStatus::PastDue);
});

it('records a successful renewal in the visible timeline', function () {
    $subscription = detailSubscription([
        'status' => SubscriptionStatus::Active->value,
        'current_period_end' => Carbon::create(2020, 2, 1, 0, 0, 0),
    ]);

    (new RenewSubscriptionAction(detailGateway(true), app(AuditLogger::class)))->execute($subscription);

    $renewal = $subscription->fresh()->changes()
        ->where('type', SubscriptionChangeType::Renewal->value)
        ->first();

    expect($renewal)->not->toBeNull();
    expect($subscription->fresh()->statusEnum())->toBe(SubscriptionStatus::Active);
});

it('keeps entitlement after a scheduled cancel and loses it on an immediate cancel', function () {
    // Scheduled cancel with the period still open: access is retained.
    $scheduled = detailSubscription(['current_period_end' => Carbon::create(2999, 1, 1, 0, 0, 0)]);
    app(CancelSubscriptionAction::class)->execute($scheduled, atPeriodEnd: true);
    expect($scheduled->fresh()->isActiveNow())->toBeTrue();

    // Cancel once the period has already elapsed is finalised immediately: access is gone.
    $elapsed = detailSubscription(['current_period_end' => Carbon::create(2020, 2, 1, 0, 0, 0)]);
    app(CancelSubscriptionAction::class)->execute($elapsed, atPeriodEnd: true);
    $fresh = $elapsed->fresh();
    expect($fresh->statusEnum())->toBe(SubscriptionStatus::Canceled);
    expect($fresh->isActiveNow())->toBeFalse();
});

it('redacts sensitive gateway references for display', function () {
    $raw = 'pi_live_secret_ABCD1234';

    $redacted = SubscriptionResource::redact($raw);

    expect($redacted)->not->toContain('secret');
    expect($redacted)->not->toBe($raw);
    expect($redacted)->toContain('1234');
    expect(SubscriptionResource::redact(null))->toBe('—');
});
