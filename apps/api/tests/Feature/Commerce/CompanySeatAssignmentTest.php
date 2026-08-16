<?php

declare(strict_types=1);

use App\Contexts\Commerce\Actions\Payment\FulfillOrderAction;
use App\Contexts\Commerce\Enums\BuyerType;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Enums\SeatReassignmentPolicy;
use App\Contexts\Commerce\Models\CompanyEntitlement;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\OrderItem;
use App\Contexts\Commerce\Models\Product;
use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Models\Course;
use App\Domains\Crm\Models\Department;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
require_once __DIR__.'/CommerceHelpers.php';

beforeEach(fn () => app(TenantContext::class)->forget());
afterEach(fn () => app(TenantContext::class)->forget());

/** A bundle product with two published courses, sold to companies under the given seat policy. */
function bundleProduct(array $policy = []): array
{
    [$courseA, $product] = courseProduct(49900);
    $courseB = Course::factory()->published()->create();
    $product->courses()->sync([$courseA->id, $courseB->id]);

    $product->forceFill(array_merge([
        'audience' => 'company',
        'seat_mode' => SeatMode::Fixed->value,
        'default_seat_count' => 2,
        'seat_reassignment_policy' => SeatReassignmentPolicy::Always->value,
        'employee_access_expires_with_purchase' => true,
    ], $policy))->save();

    return [$product->refresh(), $courseA, $courseB];
}

/** A PAID order, fulfilled through the real action so the branch under test is the one that runs. */
function fulfilledOrder(Product $product, ?Organization $org, User $buyer): Order
{
    $order = Order::create([
        'user_id' => $buyer->id,
        'status' => OrderStatus::Paid->value,
        'currency' => 'SAR',
        'subtotal_minor' => 49900, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 49900,
        'placed_at' => now(), 'paid_at' => now(),
        'buyer_type' => $org === null ? BuyerType::Individual->value : BuyerType::Company->value,
        'organization_id' => $org?->id,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'title' => $product->title,
        'unit_amount_minor' => 49900,
    ]);

    app(FulfillOrderAction::class)->execute($order);

    return $order->refresh();
}

function orgOwner(Organization $org): User
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMember::create([
        'organization_id' => $org->id, 'user_id' => $user->id, 'email' => $user->email,
        'role' => 'owner', 'status' => 'active',
    ]);

    return $user;
}

function employee(Organization $org, string $email, ?int $departmentId = null): OrganizationMember
{
    return OrganizationMember::create([
        'organization_id' => $org->id,
        'user_id' => User::factory()->create()->id,
        'email' => $email,
        'role' => 'member',
        'status' => 'active',
        'department_id' => $departmentId,
    ]);
}

// ── Fulfilment ───────────────────────────────────────────────────────────────────────────────────

it('turns a paid company order into a seat pool without enrolling the buyer', function (): void {
    [$product, $courseA] = bundleProduct();
    $org = Organization::factory()->create();
    $buyer = orgOwner($org);

    fulfilledOrder($product, $org, $buyer);

    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->first();

    expect($entitlement)->not->toBeNull()
        ->and($entitlement->seats_purchased)->toBe(2)
        ->and($entitlement->seats_used)->toBe(0)
        // The administrator who paid is not a student: nobody is enrolled until seats are handed out.
        ->and(Enrollment::where('user_id', $buyer->id)->exists())->toBeFalse()
        ->and(Enrollment::where('course_id', $courseA->id)->exists())->toBeFalse();
});

it('still enrols the buyer of an individual order', function (): void {
    [$product, $courseA, $courseB] = bundleProduct(['audience' => 'both']);
    $buyer = User::factory()->create();

    fulfilledOrder($product, null, $buyer);

    expect(Enrollment::where('user_id', $buyer->id)->where('course_id', $courseA->id)->exists())->toBeTrue()
        ->and(Enrollment::where('user_id', $buyer->id)->where('course_id', $courseB->id)->exists())->toBeTrue()
        ->and(CompanyEntitlement::count())->toBe(0);
});

it('does not create a second seat pool when fulfilment runs twice', function (): void {
    [$product] = bundleProduct();
    $org = Organization::factory()->create();
    $order = fulfilledOrder($product, $org, orgOwner($org));

    // A webhook redelivery: clear the fulfilment stamp so the action runs its body again.
    $order->forceFill(['fulfilled_at' => null])->save();
    app(FulfillOrderAction::class)->execute($order);

    expect(CompanyEntitlement::where('order_id', $order->id)->count())->toBe(1);
});

// ── Manager portal ───────────────────────────────────────────────────────────────────────────────

it('shows the manager what the company bought', function (): void {
    [$product] = bundleProduct();
    $org = Organization::factory()->create();
    fulfilledOrder($product, $org, $owner = orgOwner($org));
    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/enterprise/entitlements')
        ->assertOk()
        ->assertJsonPath('data.0.product_title', $product->title)
        ->assertJsonPath('data.0.seats.purchased', 2)
        ->assertJsonPath('data.0.seats.available', 2)
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonPath('data.0.assignable', true)
        ->assertJsonCount(2, 'data.0.courses');
});

it('assigns a bundle to one employee and shows every included course in their learning', function (): void {
    [$product, $courseA, $courseB] = bundleProduct();
    $org = Organization::factory()->create();
    fulfilledOrder($product, $org, $owner = orgOwner($org));
    $staff = employee($org, 'staff@corp.com');
    Sanctum::actingAs($owner);

    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();

    $this->postJson("/api/v1/enterprise/entitlements/{$entitlement->public_id}/assign", [
        'target_type' => 'member',
        'target_id' => $staff->public_id,
    ])
        ->assertOk()
        ->assertJsonPath('data.summary.assigned', 1)
        ->assertJsonPath('data.seats.used', 1)
        ->assertJsonPath('data.seats.available', 1);

    Sanctum::actingAs(User::find($staff->user_id));

    $this->getJson('/api/v1/my-learning')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.company_granted', true);

    expect(Enrollment::where('user_id', $staff->user_id)->pluck('course_id')->sort()->values()->all())
        ->toBe(collect([$courseA->id, $courseB->id])->sort()->values()->all());
});

it('refuses to hand out more seats than were bought', function (): void {
    [$product] = bundleProduct(['default_seat_count' => 1]);
    $org = Organization::factory()->create();
    fulfilledOrder($product, $org, $owner = orgOwner($org));
    $first = employee($org, 'first@corp.com');
    $second = employee($org, 'second@corp.com');
    Sanctum::actingAs($owner);

    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();
    $url = "/api/v1/enterprise/entitlements/{$entitlement->public_id}/assign";

    $this->postJson($url, ['target_type' => 'member', 'target_id' => $first->public_id])->assertOk();

    $this->postJson($url, ['target_type' => 'member', 'target_id' => $second->public_id])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMERCE_COMPANY_SEATS_EXHAUSTED');

    expect(CompanyEntitlement::find($entitlement->id)->seats_used)->toBe(1)
        ->and(Enrollment::where('user_id', $second->user_id)->exists())->toBeFalse();
});

it('counts a repeated assignment once', function (): void {
    [$product] = bundleProduct();
    $org = Organization::factory()->create();
    fulfilledOrder($product, $org, $owner = orgOwner($org));
    $staff = employee($org, 'repeat@corp.com');
    Sanctum::actingAs($owner);

    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();
    $url = "/api/v1/enterprise/entitlements/{$entitlement->public_id}/assign";
    $body = ['target_type' => 'member', 'target_id' => $staff->public_id];

    $this->postJson($url, $body)->assertOk()->assertJsonPath('data.seats.used', 1);

    $this->postJson($url, $body)
        ->assertOk()
        ->assertJsonPath('data.summary.assigned', 0)
        ->assertJsonPath('data.summary.already_assigned', 1)
        ->assertJsonPath('data.seats.used', 1);
});

it('assigns a whole department in one action', function (): void {
    [$product] = bundleProduct(['default_seat_count' => 5]);
    $org = Organization::factory()->create();
    fulfilledOrder($product, $org, $owner = orgOwner($org));

    $department = Department::create(['organization_id' => $org->id, 'name' => 'Sales']);
    employee($org, 'sales1@corp.com', (int) $department->getKey());
    employee($org, 'sales2@corp.com', (int) $department->getKey());
    employee($org, 'other@corp.com');

    Sanctum::actingAs($owner);
    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();

    $this->postJson("/api/v1/enterprise/entitlements/{$entitlement->public_id}/assign", [
        'target_type' => 'department',
        'target_id' => $department->public_id,
    ])
        ->assertOk()
        ->assertJsonPath('data.summary.assigned', 2)
        ->assertJsonPath('data.seats.used', 2);
});

it('cannot assign to somebody outside the organization', function (): void {
    [$product] = bundleProduct();
    $org = Organization::factory()->create();
    fulfilledOrder($product, $org, $owner = orgOwner($org));

    $otherOrg = Organization::factory()->create();
    $outsider = employee($otherOrg, 'outsider@rival.com');

    Sanctum::actingAs($owner);
    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();

    $this->postJson("/api/v1/enterprise/entitlements/{$entitlement->public_id}/assign", [
        'target_type' => 'member',
        'target_id' => $outsider->public_id,
    ])->assertStatus(404);

    expect(Enrollment::where('user_id', $outsider->user_id)->exists())->toBeFalse();
});

it('hides another organization purchase entirely', function (): void {
    [$product] = bundleProduct();
    $theirs = Organization::factory()->create();
    fulfilledOrder($product, $theirs, orgOwner($theirs));
    $entitlement = CompanyEntitlement::where('organization_id', $theirs->id)->firstOrFail();

    $mine = Organization::factory()->create();
    Sanctum::actingAs(orgOwner($mine));

    $this->getJson('/api/v1/enterprise/entitlements')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/enterprise/entitlements/{$entitlement->public_id}")->assertStatus(404);
});

// ── Expiry ───────────────────────────────────────────────────────────────────────────────────────

it('refuses to assign from a purchase whose access has ended', function (): void {
    [$product] = bundleProduct();
    $org = Organization::factory()->create();
    fulfilledOrder($product, $org, $owner = orgOwner($org));
    $staff = employee($org, 'late@corp.com');

    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();
    $entitlement->forceFill(['access_ends_at' => now()->subDay()])->save();

    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/enterprise/entitlements/{$entitlement->public_id}/assign", [
        'target_type' => 'member',
        'target_id' => $staff->public_id,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMERCE_ENTITLEMENT_NOT_ASSIGNABLE');
});

it('closes the course to an employee once the company access window ends', function (): void {
    [$product, $courseA] = bundleProduct();
    $org = Organization::factory()->create();
    fulfilledOrder($product, $org, $owner = orgOwner($org));
    $staff = employee($org, 'expiring@corp.com');

    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();

    Sanctum::actingAs($owner);
    $this->postJson("/api/v1/enterprise/entitlements/{$entitlement->public_id}/assign", [
        'target_type' => 'member', 'target_id' => $staff->public_id,
    ])->assertOk();

    // The purchase lapses; the enrollment's own window lapses with it.
    Enrollment::where('user_id', $staff->user_id)->update(['expires_at' => now()->subMinute()]);

    Sanctum::actingAs(User::find($staff->user_id));

    $this->getJson("/api/v1/courses/{$courseA->public_id}/learn")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'LEARNING_ACCESS_EXPIRED');

    $this->getJson('/api/v1/my-learning')->assertOk()->assertJsonPath('data.0.expired', true);
});

it('never expires access the learner bought for themselves', function (): void {
    [$product, $courseA] = bundleProduct(['audience' => 'both']);
    $org = Organization::factory()->create();
    fulfilledOrder($product, $org, orgOwner($org));

    // The same person also bought the bundle personally.
    $staff = employee($org, 'both@corp.com');
    $staffUser = User::find($staff->user_id);
    fulfilledOrder($product, null, $staffUser);

    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();
    Sanctum::actingAs(User::find(OrganizationMember::where('organization_id', $org->id)->where('role', 'owner')->first()->user_id));

    $this->postJson("/api/v1/enterprise/entitlements/{$entitlement->public_id}/assign", [
        'target_type' => 'member', 'target_id' => $staff->public_id,
    ])->assertOk();

    $personal = Enrollment::where('user_id', $staffUser->id)->where('course_id', $courseA->id)->firstOrFail();

    // Their own purchase kept its source and never picked up the company's expiry.
    expect($personal->source)->toBe(EnrollmentSource::Purchase)
        ->and($personal->expires_at)->toBeNull();
});

// ── Reassignment policy ──────────────────────────────────────────────────────────────────────────

it('lets a seat be revoked when the policy allows it', function (): void {
    [$product, $courseA] = bundleProduct(['seat_reassignment_policy' => SeatReassignmentPolicy::Always->value]);
    $org = Organization::factory()->create();
    fulfilledOrder($product, $org, $owner = orgOwner($org));
    $staff = employee($org, 'movable@corp.com');
    Sanctum::actingAs($owner);

    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();

    $this->postJson("/api/v1/enterprise/entitlements/{$entitlement->public_id}/assign", [
        'target_type' => 'member', 'target_id' => $staff->public_id,
    ])->assertOk();

    $this->postJson("/api/v1/enterprise/entitlements/{$entitlement->public_id}/revoke", [
        'member_id' => $staff->public_id,
    ])
        ->assertOk()
        ->assertJsonPath('data.seats.used', 0)
        ->assertJsonPath('data.seats.available', 2);

    $enrollment = Enrollment::where('user_id', $staff->user_id)->where('course_id', $courseA->id)->firstOrFail();
    expect($enrollment->status)->toBe(EnrollmentStatus::Cancelled);
});

it('blocks a revoke when the product says a seat is never reassignable', function (): void {
    [$product] = bundleProduct(['seat_reassignment_policy' => SeatReassignmentPolicy::Never->value]);
    $org = Organization::factory()->create();
    fulfilledOrder($product, $org, $owner = orgOwner($org));
    $staff = employee($org, 'stuck@corp.com');
    Sanctum::actingAs($owner);

    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();

    $this->postJson("/api/v1/enterprise/entitlements/{$entitlement->public_id}/assign", [
        'target_type' => 'member', 'target_id' => $staff->public_id,
    ])->assertOk();

    $this->postJson("/api/v1/enterprise/entitlements/{$entitlement->public_id}/revoke", [
        'member_id' => $staff->public_id,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMERCE_SEAT_REASSIGNMENT_BLOCKED');

    expect(CompanyEntitlement::find($entitlement->id)->seats_used)->toBe(1);
});

it('blocks a before-start revoke once the employee has made progress', function (): void {
    [$product, $courseA] = bundleProduct(['seat_reassignment_policy' => SeatReassignmentPolicy::BeforeStart->value]);
    $org = Organization::factory()->create();
    fulfilledOrder($product, $org, $owner = orgOwner($org));
    $staff = employee($org, 'started@corp.com');
    Sanctum::actingAs($owner);

    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();
    $assign = "/api/v1/enterprise/entitlements/{$entitlement->public_id}/assign";
    $revoke = "/api/v1/enterprise/entitlements/{$entitlement->public_id}/revoke";

    $this->postJson($assign, ['target_type' => 'member', 'target_id' => $staff->public_id])->assertOk();

    Enrollment::where('user_id', $staff->user_id)->where('course_id', $courseA->id)
        ->update(['progress_percentage' => 12]);

    $this->postJson($revoke, ['member_id' => $staff->public_id])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COMMERCE_SEAT_REASSIGNMENT_BLOCKED');
});

it('allows a progress-threshold revoke below the threshold and blocks it above', function (): void {
    [$product, $courseA] = bundleProduct([
        'seat_reassignment_policy' => SeatReassignmentPolicy::BeforeProgressThreshold->value,
        'reassignment_progress_threshold' => 50,
        'default_seat_count' => 3,
    ]);
    $org = Organization::factory()->create();
    fulfilledOrder($product, $org, $owner = orgOwner($org));
    $early = employee($org, 'early@corp.com');
    $deep = employee($org, 'deep@corp.com');
    Sanctum::actingAs($owner);

    $entitlement = CompanyEntitlement::where('organization_id', $org->id)->firstOrFail();
    $assign = "/api/v1/enterprise/entitlements/{$entitlement->public_id}/assign";
    $revoke = "/api/v1/enterprise/entitlements/{$entitlement->public_id}/revoke";

    $this->postJson($assign, ['target_type' => 'member', 'target_id' => $early->public_id])->assertOk();
    $this->postJson($assign, ['target_type' => 'member', 'target_id' => $deep->public_id])->assertOk();

    Enrollment::where('user_id', $early->user_id)->where('course_id', $courseA->id)->update(['progress_percentage' => 40]);
    Enrollment::where('user_id', $deep->user_id)->where('course_id', $courseA->id)->update(['progress_percentage' => 80]);

    $this->postJson($revoke, ['member_id' => $early->public_id])->assertOk();
    $this->postJson($revoke, ['member_id' => $deep->public_id])->assertStatus(409);
});

// ── Authorization ────────────────────────────────────────────────────────────────────────────────

it('denies a plain employee the training portal', function (): void {
    [$product] = bundleProduct();
    $org = Organization::factory()->create();
    fulfilledOrder($product, $org, orgOwner($org));

    $staff = employee($org, 'plain@corp.com');
    $staffUser = User::find($staff->user_id);
    $staffUser->forceFill(['organization_id' => $org->id])->save();
    Sanctum::actingAs($staffUser);

    $this->getJson('/api/v1/enterprise/entitlements')->assertForbidden();
});

// The purchase portal and the subscription seat pool are two different surfaces that both talk about
// "seats". This wave's predecessor gave them the same form-request class, and the subscription one —
// which takes a single member_id, not a target scope — started rejecting every call with a 422.
it('leaves the subscription seat endpoints speaking their own request shape', function (): void {
    $org = Organization::factory()->create();
    Sanctum::actingAs(orgOwner($org));
    $member = employee($org, 'seat@corp.com');

    // No subscription, so there is no pool: the point is that validation lets the request THROUGH to
    // the controller (404/409, never a 422 for a body that is perfectly well formed).
    $response = $this->postJson('/api/v1/enterprise/seats/assign', ['member_id' => $member->public_id]);

    expect($response->status())->not->toBe(422);
});
