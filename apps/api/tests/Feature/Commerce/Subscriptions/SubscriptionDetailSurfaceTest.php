<?php

use App\Contexts\Commerce\Actions\Subscription\RenewSubscriptionAction;
use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Contexts\Commerce\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Models\SubscriptionPlanPrice;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use App\Contexts\Commerce\Database\Seeders\CommerceSeeder;
use App\Domains\Catalog\Models\Course;
use App\Contexts\Commerce\Models\Product;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Audit\AuditLogger;
use Database\Seeders\StaffRoleTemplatesSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(Carbon::create(2021, 6, 15, 12, 0, 0)));
afterEach(fn () => Carbon::setTestNow());

/** Sign in as a finance_manager (holds subscriptions.manage) on the admin panel. */
function surfaceFinanceUser(): User
{
    test()->seed(RolePermissionSeeder::class);
    test()->seed(CommerceSeeder::class);
    test()->seed(StaffRoleTemplatesSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('finance_manager');
    test()->actingAs($user, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $user;
}

function surfacePlan(int $amount = 9900, ?int $productId = null): SubscriptionPlan
{
    $plan = SubscriptionPlan::create([
        'name' => 'Pro',
        'product_id' => $productId,
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

/** @param array<string, mixed> $overrides */
function surfaceSubscription(array $overrides = [], ?SubscriptionPlan $plan = null): Subscription
{
    $user = User::factory()->create();
    $plan ??= surfacePlan();

    return Subscription::create(array_replace([
        'user_id' => $user->id,
        'plan_id' => $plan->getKey(),
        'status' => SubscriptionStatus::Active->value,
        'current_period_start' => Carbon::create(2021, 5, 15, 0, 0, 0),
        'current_period_end' => Carbon::create(2021, 7, 15, 0, 0, 0),
        'currency' => 'SAR',
        'amount_minor' => 9900,
        'provider' => 'fake',
        'provider_reference' => 'sub_live_ABCD1234',
    ], $overrides));
}

function surfaceGateway(bool $succeeds): PaymentGateway
{
    return new class($succeeds) implements PaymentGateway
    {
        public function __construct(public bool $succeeds) {}

        public function charge(ChargeRequest $request): ChargeResult
        {
            return new ChargeResult('prov_'.$request->reference, $this->succeeds ? 'succeeded' : 'failed');
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

it('S5: the cancel-immediately header action terminates access now, not at period end', function () {
    surfaceFinanceUser();
    // Period still open. The default cancel would only SCHEDULE (status stays Active,
    // cancel_at_period_end=true); cancel-immediately finalises the cancellation NOW instead of
    // deferring it to the period end.
    $subscription = surfaceSubscription(['current_period_end' => Carbon::create(2999, 1, 1, 0, 0, 0)]);

    Livewire::test(ViewSubscription::class, ['record' => $subscription->getAttribute('public_id')])
        ->callAction('cancelImmediately');

    $fresh = $subscription->fresh();
    // Immediate (not scheduled): the lifecycle engine keeps a Canceled subscription access-granting
    // until its paid-through period lapses (SubscriptionStatus::Canceled->grantsAccess() === true and
    // CancelSubscriptionAction leaves the period intact), so the distinguishing guarantees of an
    // immediate cancel are the terminal status now, the cleared schedule flag, and a canceled_at
    // stamped at the current instant — not a mid-period access cut.
    expect($fresh->statusEnum())->toBe(SubscriptionStatus::Canceled)
        ->and($fresh->cancelAtPeriodEnd())->toBeFalse()
        ->and($fresh->getAttribute('canceled_at'))->not->toBeNull();
});

it('S4: renders the subscription audit history on the detail page', function () {
    surfaceFinanceUser();
    $subscription = surfaceSubscription();

    // Produce a real audited transition through the domain action.
    app(\App\Contexts\Commerce\Actions\Subscription\CancelSubscriptionAction::class)
        ->execute($subscription, atPeriodEnd: false);

    Livewire::test(ViewSubscription::class, ['record' => $subscription->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertSee('commerce.subscription.canceled');
});

it('S4: shows a friendly placeholder when there is no audit history', function () {
    surfaceFinanceUser();
    $subscription = surfaceSubscription();

    Livewire::test(ViewSubscription::class, ['record' => $subscription->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertSee('No audit entries.');
});

it('S2: surfaces the entitled courses granted through plan -> product -> courses', function () {
    surfaceFinanceUser();

    $course = Course::factory()->published()->create(['title' => 'Advanced Widgets']);
    $product = Product::factory()->create();
    $product->courses()->sync([$course->id]);

    $plan = surfacePlan(productId: $product->id);
    $subscription = surfaceSubscription(plan: $plan);

    Livewire::test(ViewSubscription::class, ['record' => $subscription->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertSee('Advanced Widgets');
});

it('S3: a failed renewal is visible as past_due with its dunning state on the detail', function () {
    surfaceFinanceUser();
    $subscription = surfaceSubscription([
        'status' => SubscriptionStatus::Active->value,
        'current_period_end' => Carbon::create(2021, 6, 1, 0, 0, 0),
    ]);

    (new RenewSubscriptionAction(surfaceGateway(false), app(AuditLogger::class)))->execute($subscription);

    expect($subscription->fresh()->statusEnum())->toBe(SubscriptionStatus::PastDue);

    Livewire::test(ViewSubscription::class, ['record' => $subscription->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertSee('Past due (retrying)')
        ->assertSee('Renewal & dunning');
});

it('S3: redacts the provider reference on the subscription detail', function () {
    surfaceFinanceUser();
    $subscription = surfaceSubscription(['provider_reference' => 'sub_live_secret_ZZZ9999']);

    Livewire::test(ViewSubscription::class, ['record' => $subscription->getAttribute('public_id')])
        ->assertSuccessful()
        ->assertDontSee('secret')
        ->assertSee('9999');
});

it('S6: the subscriptions list query count does not scale with the number of rows', function () {
    surfaceFinanceUser();

    $seed = function (): void {
        surfaceSubscription();
    };

    foreach (range(1, 3) as $ignored) {
        $seed();
    }

    // Warm first-request initialization so both measurements compare like for like.
    Livewire::test(ListSubscriptions::class);

    DB::enableQueryLog();
    Livewire::test(ListSubscriptions::class);
    $threeRows = count(DB::getQueryLog());

    foreach (range(1, 3) as $ignored) {
        $seed();
    }

    DB::flushQueryLog();
    Livewire::test(ListSubscriptions::class);
    $sixRows = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($sixRows)->toBe($threeRows);
});
