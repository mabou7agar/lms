<?php

declare(strict_types=1);

use App\Contexts\Commerce\Actions\Subscription\SubscribeOrganizationAction;
use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Contexts\Commerce\Models\SubscriptionPlanPrice;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Models\Course;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\SeatAssignment;
use App\Domains\Crm\Models\SeatPool;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Audit\AuditLogger;
use App\Platform\Shared\Enterprise\Contracts\ManagerReportPort;
use App\Platform\Shared\Seats\Contracts\SeatProvisioningPort;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(fn () => app(TenantContext::class)->forget());
afterEach(fn () => app(TenantContext::class)->forget());

function fakeGateway(): PaymentGateway
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

function subscribeOrg(Organization $org, int $seats): void
{
    $plan = SubscriptionPlan::create(['name' => 'Team', 'interval' => 'monthly', 'trial_days' => 0, 'is_active' => true]);
    SubscriptionPlanPrice::create(['plan_id' => $plan->getKey(), 'currency' => 'SAR', 'amount_minor' => 9900, 'is_default' => true]);

    (new SubscribeOrganizationAction(fakeGateway(), app(AuditLogger::class), app(SeatProvisioningPort::class)))
        ->execute($org->id, $plan->load('prices'), seats: $seats, currency: 'SAR');
}

/** An owner user whose tenant resolves to $org. */
function ownerOf(Organization $org): User
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMember::create(['organization_id' => $org->id, 'user_id' => $user->id, 'email' => $user->email, 'role' => 'owner', 'status' => 'active']);

    return $user;
}

function orgEmployee(Organization $org, string $email): OrganizationMember
{
    return OrganizationMember::create(['organization_id' => $org->id, 'user_id' => User::factory()->create()->id, 'email' => $email, 'role' => 'member', 'status' => 'active']);
}

it('shows seat usage, assigns, rejects a downgrade below assigned, then releases and resizes', function (): void {
    $org = Organization::factory()->create();
    subscribeOrg($org, 2);
    Sanctum::actingAs(ownerOf($org));

    $a = orgEmployee($org, 'a@corp.com');
    $b = orgEmployee($org, 'b@corp.com');

    $this->getJson('/api/v1/enterprise/seats')->assertOk()->assertJsonPath('data.seats.purchased', 2)->assertJsonPath('data.seats.used', 0);

    $this->postJson('/api/v1/enterprise/seats/assign', ['member_id' => $a->public_id])->assertOk()->assertJsonPath('data.seats.used', 1);
    $this->postJson('/api/v1/enterprise/seats/assign', ['member_id' => $b->public_id])->assertOk()->assertJsonPath('data.seats.used', 2);

    // 2 assigned -> resize to 1 rejected.
    $this->postJson('/api/v1/enterprise/seats/resize', ['seats' => 1])->assertStatus(409);

    $this->postJson('/api/v1/enterprise/seats/release', ['member_id' => $b->public_id])->assertOk()->assertJsonPath('data.seats.used', 1);
    $this->postJson('/api/v1/enterprise/seats/resize', ['seats' => 1])->assertOk()->assertJsonPath('data.seats.purchased', 1);

    $this->getJson('/api/v1/enterprise/seats/history')->assertOk()->assertJsonPath('meta.total', 2);
});

it('removing a member releases their seat', function (): void {
    $org = Organization::factory()->create();
    subscribeOrg($org, 2);
    Sanctum::actingAs(ownerOf($org));

    $member = orgEmployee($org, 'x@corp.com');
    $this->postJson('/api/v1/enterprise/seats/assign', ['member_id' => $member->public_id])->assertOk();

    $pool = SeatPool::where('organization_id', $org->id)->firstOrFail();
    expect($pool->fresh()->used_seats)->toBe(1);

    $this->deleteJson("/api/v1/enterprise/members/{$member->public_id}")->assertOk();

    expect($pool->fresh()->used_seats)->toBe(0)
        ->and(SeatAssignment::where('member_id', $member->id)->whereNull('revoked_at')->exists())->toBeFalse()
        ->and($member->fresh()->status->value)->toBe('removed');
});

it('accepts an invitation, linking and activating the membership', function (): void {
    $org = Organization::factory()->create();
    $invited = OrganizationMember::create([
        'organization_id' => $org->id, 'email' => 'new@corp.com', 'role' => 'member',
        'status' => 'invited', 'invitation_token' => 'tok_'.str_repeat('a', 60), 'invited_at' => now(),
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/enterprise/invitations/tok_'.str_repeat('a', 60).'/accept')
        ->assertOk()->assertJsonPath('data.status', 'active');

    expect($invited->fresh()->user_id)->toBe($user->id)
        ->and($invited->fresh()->invitation_token)->toBeNull();
});

it('denies a plain member every manager endpoint', function (): void {
    $org = Organization::factory()->create();
    $plain = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMember::create(['organization_id' => $org->id, 'user_id' => $plain->id, 'email' => $plain->email, 'role' => 'member', 'status' => 'active']);
    Sanctum::actingAs($plain);

    $this->getJson('/api/v1/enterprise/report')->assertForbidden();
    $this->getJson('/api/v1/enterprise/members')->assertForbidden();
    $this->postJson('/api/v1/enterprise/seats/assign', ['member_id' => (string) Str::uuid()])->assertForbidden();
    $this->postJson('/api/v1/enterprise/departments', ['name' => 'X'])->assertForbidden();
});

it('bounds the manager report query count regardless of learner volume', function (): void {
    $org = Organization::factory()->create();
    $course = Course::factory()->published()->create();

    foreach (range(1, 25) as $i) {
        $u = User::factory()->create();
        OrganizationMember::create(['organization_id' => $org->id, 'user_id' => $u->id, 'email' => "u{$i}@corp.com", 'role' => 'member', 'status' => 'active']);
        Enrollment::factory()->create(['user_id' => $u->id, 'course_id' => $course->id, 'progress_percentage' => 10]);
    }

    DB::enableQueryLog();
    app(ManagerReportPort::class)->report($org->id, null, 30);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // A handful of bounded aggregates — NOT a query per learner.
    expect($count)->toBeLessThan(12);
});
