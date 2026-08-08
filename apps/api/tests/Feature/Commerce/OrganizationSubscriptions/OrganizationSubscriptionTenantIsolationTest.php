<?php

namespace Tests\Feature\Commerce\OrganizationSubscriptions;

use App\Contexts\Commerce\Actions\Subscription\CancelSubscriptionAction;
use App\Contexts\Commerce\Actions\Subscription\ChangeOrganizationSeatsAction;
use App\Contexts\Commerce\Actions\Subscription\SubscribeAction;
use App\Contexts\Commerce\Actions\Subscription\SubscribeOrganizationAction;
use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Exceptions\OrganizationSubscriptionAccessDeniedException;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Models\SubscriptionPlanPrice;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use App\Contexts\Commerce\Services\OrganizationSubscriptionService;
use App\Contexts\Commerce\Services\SubscriptionService;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\SeatPool;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Audit\AuditLogger;
use App\Platform\Shared\Seats\Contracts\SeatProvisioningPort;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * T1 adversarial isolation for COMMERCE ORGANIZATION SUBSCRIPTIONS + SEATS.
 *
 * These prove the explicit org-boundary that replaces a (forbidden) table-wide scope on `subscriptions`
 * (T1_TENANT_OWNERSHIP_MATRIX §4). All fixtures are built with NO active tenant so they are stamped to
 * their real organizations; the attack then activates org1 as the request tenant and asserts org2's
 * subscription, seat pool, and seat assignments are all unreachable — while individual (personal)
 * subscriptions stay visible and unaffected (no scope was added to `subscriptions`).
 *
 * Gateway I/O is a deterministic in-memory fake; money is integer minor units.
 */

function tiGateway(): PaymentGateway
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

function tiPlan(int $amount = 9900): SubscriptionPlan
{
    $plan = SubscriptionPlan::create([
        'name' => 'Team',
        'product_id' => null,
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

function tiMember(Organization $org, ?User $user = null, string $email = 'e@corp.com'): OrganizationMember
{
    return OrganizationMember::create([
        'organization_id' => $org->id,
        'user_id' => $user?->id,
        'email' => $email,
        'role' => 'member',
        'status' => 'active',
    ]);
}

/** Build an active organization subscription (with its seat pool) OUTSIDE any tenant context. */
function tiSubscribeOrg(Organization $org, int $seats = 3): Subscription
{
    return app(TenantContext::class)->runWithoutTenancy(fn (): Subscription => (new SubscribeOrganizationAction(
        tiGateway(),
        app(AuditLogger::class),
        app(SeatProvisioningPort::class),
    ))->execute($org->id, tiPlan(), seats: $seats, currency: 'SAR'));
}

/** Activate an explicit request tenant for the duration of the assertions. */
function tiActAsTenant(int $organizationId): void
{
    app(TenantContext::class)->set(TenantId::from($organizationId));
}

beforeEach(function (): void {
    app(TenantContext::class)->forget();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

it('forbids org1 from READING org2 organization subscription', function (): void {
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    tiSubscribeOrg($org2, seats: 4);

    tiActAsTenant($org1->id);

    $service = app(OrganizationSubscriptionService::class);

    expect(fn () => $service->organizationSubscription($org2->id))
        ->toThrow(OrganizationSubscriptionAccessDeniedException::class);
    expect(fn () => $service->summary($org2->id))
        ->toThrow(OrganizationSubscriptionAccessDeniedException::class);
});

it('forbids org1 from RESIZING org2 organization subscription seats', function (): void {
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    $org2Sub = tiSubscribeOrg($org2, seats: 3);

    tiActAsTenant($org1->id);

    expect(fn () => app(ChangeOrganizationSeatsAction::class)->execute($org2Sub, 10))
        ->toThrow(OrganizationSubscriptionAccessDeniedException::class);

    // Capacity untouched (read back with tenancy bypassed).
    $seats = app(TenantContext::class)->runWithoutTenancy(
        fn (): ?int => Subscription::findOrFail($org2Sub->getKey())->seats(),
    );
    expect($seats)->toBe(3);
});

it('forbids org1 from CANCELING org2 subscription by denying it a handle to org2 through the org-sub surface', function (): void {
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    tiSubscribeOrg($org2, seats: 2);

    tiActAsTenant($org1->id);

    // The sanctioned way an org admin loads "their" org subscription is the org-sub read surface; it
    // refuses org2, so an org1 caller never obtains the model to hand to the shared cancel/change-plan.
    expect(fn () => app(OrganizationSubscriptionService::class)->organizationSubscription($org2->id))
        ->toThrow(OrganizationSubscriptionAccessDeniedException::class);
});

it('forbids org1 from ASSIGNING or UNASSIGNING org2 employees to seats', function (): void {
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    $org2Sub = tiSubscribeOrg($org2, seats: 3);
    $org2Member = app(TenantContext::class)->runWithoutTenancy(fn (): OrganizationMember => tiMember($org2, email: 'victim@org2.com'));

    tiActAsTenant($org1->id);

    $service = app(OrganizationSubscriptionService::class);

    expect(fn () => $service->assignEmployee($org2Sub, $org2Member->id))
        ->toThrow(OrganizationSubscriptionAccessDeniedException::class);
    expect(fn () => $service->unassignEmployee($org2Sub, $org2Member->id))
        ->toThrow(OrganizationSubscriptionAccessDeniedException::class);
    expect(fn () => $service->seatUsage($org2Sub))
        ->toThrow(OrganizationSubscriptionAccessDeniedException::class);
});

it('isolates org2 seat pools and assignments from org1 via the strict CRM pool scope', function (): void {
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    $org2Sub = tiSubscribeOrg($org2, seats: 3);
    $org2PoolId = (int) $org2Sub->seatPoolId();

    // Seat one org2 employee (outside any tenant context) so there is a real assignment to leak.
    app(TenantContext::class)->runWithoutTenancy(function () use ($org2, $org2Sub): void {
        app(OrganizationSubscriptionService::class)->assignEmployee($org2Sub, tiMember($org2, email: 's@org2.com')->id);
    });

    tiActAsTenant($org1->id);

    // The org2 seat pool is invisible to org1 (SeatPool uses strict BelongsToTenant).
    expect(SeatPool::find($org2PoolId))->toBeNull();

    // seat_assignments ride their scoped pool: the port resolves the pool first, so a cross-tenant
    // read returns an all-zero snapshot and a cross-tenant write is a silent no-op — no leak, no edit.
    $port = app(SeatProvisioningPort::class);
    $counts = $port->seatCounts($org2PoolId);
    expect($counts->purchased)->toBe(0)->and($counts->assigned)->toBe(0)->and($counts->available)->toBe(0);

    $intruder = app(TenantContext::class)->runWithoutTenancy(fn (): OrganizationMember => tiMember($org1, email: 'intruder@org1.com'));
    $port->assignSeat($org2PoolId, $intruder->id); // no-op: pool not visible under org1

    // org2's pool is unchanged: still 1 used seat, and the intruder never got assigned.
    app(TenantContext::class)->runWithoutTenancy(function () use ($org2PoolId, $intruder): void {
        $pool = SeatPool::findOrFail($org2PoolId);
        expect($pool->used_seats)->toBe(1)
            ->and($pool->assignments()->where('member_id', $intruder->id)->exists())->toBeFalse();
    });
});

it('rejects a forged organization_id: the target is honored only when it matches the authenticated tenant', function (): void {
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();

    // A genuine org1 employee (no platform role) — the resolver derives the tenant from their org.
    $org1User = User::factory()->create(['organization_id' => $org1->id]);
    $this->actingAs($org1User);
    app(TenantContext::class)->forget(); // re-arm lazy resolution now that a user is acting

    $action = new SubscribeOrganizationAction(tiGateway(), app(AuditLogger::class), app(SeatProvisioningPort::class));

    // Forged: a request body claiming org2 is rejected — the value is checked against auth, not trusted.
    expect(fn () => $action->execute($org2->id, tiPlan(), seats: 2, currency: 'SAR'))
        ->toThrow(OrganizationSubscriptionAccessDeniedException::class);

    // Honest: the same call for the caller's own org is allowed.
    $own = $action->execute($org1->id, tiPlan(), seats: 2, currency: 'SAR');
    expect($own->isOrganization())->toBeTrue()
        ->and($own->organizationId())->toBe($org1->id);
});

it('leaves individual (personal) user subscriptions visible and unaffected under an active tenant (no scope on subscriptions)', function (): void {
    // A personal subscription for a user with NO organization (the pre-existing path), built untenanted.
    $plan = app(TenantContext::class)->runWithoutTenancy(fn (): SubscriptionPlan => tiPlan());
    $user = User::factory()->create(); // organization_id is null
    $userSub = app(TenantContext::class)->runWithoutTenancy(fn (): Subscription => (new SubscribeAction(tiGateway(), app(AuditLogger::class)))
        ->execute($user->id, $plan, 'SAR'));

    // Now some OTHER org is the active tenant. A blanket strict scope on `subscriptions` would hide the
    // personal (org NULL) row — it must stay fully visible, proving no such scope was added.
    $other = Organization::factory()->create();
    tiActAsTenant($other->id);

    expect(Subscription::find($userSub->getKey()))->not->toBeNull();

    // The user-only resolver still returns exactly the individual subscription, unchanged.
    $resolved = app(SubscriptionService::class)->activeSubscriptionForUser($user->id);
    expect($resolved?->getKey())->toBe($userSub->getKey())
        ->and($resolved?->isOrganization())->toBeFalse()
        ->and($resolved?->getAttribute('organization_id'))->toBeNull();

    // The individual owner can still be canceled by the shared action (no org guard interferes).
    (new CancelSubscriptionAction(app(AuditLogger::class)))->execute($userSub->fresh(), atPeriodEnd: false);
    expect($userSub->fresh()->statusEnum())->toBe(SubscriptionStatus::Canceled);
});
