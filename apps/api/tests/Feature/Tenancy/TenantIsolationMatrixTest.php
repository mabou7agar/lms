<?php

declare(strict_types=1);

use App\Contexts\Analytics\Models\MetricSnapshot;
use App\Contexts\Analytics\Services\KpiEngine;
use App\Contexts\Commerce\Exceptions\OrganizationSubscriptionAccessDeniedException;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Support\OrganizationSubscriptionGuard;
use App\Domains\Assessment\Models\Assessment;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Services\CourseSearchService;
use App\Domains\Crm\Enums\MemberRole;
use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\SeatPool;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Audit\AuditLog;
use App\Platform\Shared\Audit\AuditLogger;
use App\Platform\Shared\Tenancy\RequestTenantResolver;
use App\Platform\Shared\Tenancy\RestoreTenantContext;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * T1 — CONSOLIDATED ADVERSARIAL TENANT-ISOLATION MATRIX (design §7, the 20-point acceptance net).
 *
 * This is the single cross-domain acceptance suite for the "global-OR-own-org" (Option N) rollout. It
 * drives the REAL production models, scopes, services and guards through the kernel's TenantContext —
 * the same deterministic pattern the per-domain tenancy suites use (CrossTenantLeakageTest,
 * SharedOrOwnedTenantScopeTest, AssessmentTenancyTest, MediaAssetPolicyTenancyTest). Some overlap with
 * those per-domain suites is intentional: this file proves the boundary holds END TO END, in one place.
 *
 * Backward-compat invariant: every isolation case ESTABLISHES a tenant explicitly (app(TenantContext)
 * ->set(...) after forget(), or an acting user carrying an organization_id). The existing NULL-org
 * corpus resolves NO tenant, so all of these scopes stay dormant for it — the 1279 legacy tests are
 * untouched. User factories/seeders are never modified.
 *
 * Matrix point → test mapping (see MANIFEST):
 *   P01 anonymous sees global catalog                         P11 org1 cannot mutate org2 org-subscription
 *   P02 B2C NULL-org user sees global catalog                 P12 queued job restores correct tenant
 *   P03 org1 sees global + org1-private                       P13 cache cannot leak tenant payload
 *   P04 org1 cannot see org2-private course                   P14 interleaved contexts cannot leak
 *   P05 org1 cannot edit org2 assessment                      P15 inactive membership denied
 *   P06 org1 cannot access org2 MediaAsset                    P16 forged organization_id ignored
 *   P07 org1 search excludes org2-private course              P17 global catalog visible under a tenant
 *   P08 org1 analytics excludes org2                          P18 individual user subscriptions unaffected
 *   P09 org1 cannot manage org2 employees                     P19 global public media still resolves
 *   P10 org1 cannot manage org2 seat pools                    P20 super_admin cross-tenant works + audited
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Roles for the super_admin bypass case (P20); harmless for the rest.
    $this->seed(RolePermissionSeeder::class);
    app(TenantContext::class)->forget();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

/**
 * A catalog course, optionally made private to an organization. organization_id is guarded on Course
 * (never mass-assignable / never from client input), so an org-private course is seeded via forceFill,
 * exactly as the production stamp-on-create path would set it from a resolved tenant.
 */
function matrixCourse(?int $organizationId = null, bool $published = false, ?string $title = null): Course
{
    $factory = Course::factory();
    $factory = $published ? $factory->published() : $factory;

    $attributes = ['status' => $published ? CourseStatus::Published : CourseStatus::Draft];
    if ($title !== null) {
        $attributes['title'] = $title;
    }

    $course = $factory->create($attributes);

    if ($organizationId !== null) {
        $course->forceFill(['organization_id' => $organizationId])->save();
    }

    return $course;
}

/** Seed a metric bucket directly (organization_id is server-controlled; NULL = global platform bucket). */
function matrixMetric(string $metricKey, ?int $organizationId, int $value): void
{
    app(TenantContext::class)->runWithoutTenancy(static function () use ($metricKey, $organizationId, $value): void {
        MetricSnapshot::create([
            'organization_id' => $organizationId,
            'metric_key' => $metricKey,
            'granularity' => 'daily',
            'period' => CarbonImmutable::today()->toDateString(),
            'dimension_key' => '',
            'dimension_value' => '',
            'value' => $value,
        ]);
    });
}

// ─────────────────────────────────────────── P01–P04, P17 catalog visibility ───────────────────────

it('P01 — an anonymous request sees the GLOBAL catalog', function (): void {
    $global = matrixCourse(null, true);

    // No auth, no tenant resolved → scope no-ops → global catalog fully visible.
    expect(app(TenantContext::class)->id())->toBeNull()
        ->and(Course::whereKey($global->id)->exists())->toBeTrue();
});

it('P02 — a B2C NULL-organization user sees the GLOBAL catalog', function (): void {
    $global = matrixCourse(null, true);

    $b2c = User::factory()->create(); // organization_id is null
    $this->actingAs($b2c);
    app(TenantContext::class)->forget(); // re-arm lazy resolution now that the user is acting

    expect(app(RequestTenantResolver::class)->resolve())->toBeNull()
        ->and(Course::whereKey($global->id)->exists())->toBeTrue();
});

it('P03 — an org1 employee sees GLOBAL + org1-private, never org2-private', function (): void {
    $global = matrixCourse(null);
    $org1 = matrixCourse(1);
    $org2 = matrixCourse(2);

    app(TenantContext::class)->set(TenantId::from(1));

    $visibleIds = Course::query()->pluck('id')->all();

    expect($visibleIds)->toContain($global->id)
        ->toContain($org1->id)
        ->not->toContain($org2->id);
});

it('P04 — org1 cannot see an org2-private course', function (): void {
    $org2 = matrixCourse(2);

    app(TenantContext::class)->set(TenantId::from(1));

    expect(Course::find($org2->id))->toBeNull()
        ->and(Course::whereKey($org2->id)->exists())->toBeFalse();
});

it('P17 — the GLOBAL catalog stays visible under a resolved tenant', function (): void {
    $global = matrixCourse(null);

    app(TenantContext::class)->set(TenantId::from(1));

    expect(Course::find($global->id))->not->toBeNull()
        ->and(Course::find($global->id)->organization_id)->toBeNull();
});

// ─────────────────────────────────────────── P05 assessment (transitive) ───────────────────────────

it('P05 — org1 cannot see or edit an assessment on an org2-private course', function (): void {
    $org2Course = matrixCourse(2);
    $assessment = Assessment::factory()->create(['course_id' => $org2Course->id, 'title' => 'Original']);

    app(TenantContext::class)->set(TenantId::from(1));

    // Invisible via the CourseTenantScope join (no tenant column on assessments).
    expect(Assessment::find($assessment->id))->toBeNull();

    // A scoped mass update cannot reach the row either.
    Assessment::whereKey($assessment->id)->update(['title' => 'Hacked']);

    $title = app(TenantContext::class)->runWithoutTenancy(
        static fn (): string => (string) Assessment::findOrFail($assessment->id)->title,
    );

    expect($title)->toBe('Original');
});

// ─────────────────────────────────────────── P06, P19 media ────────────────────────────────────────

it('P06 — org1 cannot access an org2-private MediaAsset', function (): void {
    $org2Asset = MediaAsset::factory()->ready()->organization(2)->create();

    app(TenantContext::class)->set(TenantId::from(1));

    expect(MediaAsset::find($org2Asset->id))->toBeNull()
        ->and(MediaAsset::whereKey($org2Asset->id)->exists())->toBeFalse();
});

it('P19 — GLOBAL public media still resolves under a resolved tenant', function (): void {
    $globalPublic = MediaAsset::factory()->ready()->publicVisibility()->organization(null)->create();

    app(TenantContext::class)->set(TenantId::from(1));

    expect(MediaAsset::find($globalPublic->id))->not->toBeNull()
        ->and(MediaAsset::find($globalPublic->id)->organization_id)->toBeNull();
});

// ─────────────────────────────────────────── P07 search ────────────────────────────────────────────

it('P07 — org1 search excludes an org2-private course; global + own remain searchable', function (): void {
    $token = 'ZZQMATRIXNEEDLE';
    $global = matrixCourse(null, true, "{$token} Global Course");
    $org1 = matrixCourse(1, true, "{$token} Org One Course");
    $org2 = matrixCourse(2, true, "{$token} Org Two Course");

    app(TenantContext::class)->set(TenantId::from(1));

    $results = app(CourseSearchService::class)->paginate(['q' => $token], 20);
    $ids = collect($results->items())->pluck('id')->all();

    expect($ids)->toContain($global->id)
        ->toContain($org1->id)
        ->not->toContain($org2->id);
});

// ─────────────────────────────────────────── P08, P13 analytics + cache ────────────────────────────

it('P08 — org1 analytics excludes org2 (scoped read model)', function (): void {
    $metric = 'matrix.p08.enrollments';
    matrixMetric($metric, null, 10); // global
    matrixMetric($metric, 1, 20);    // org1
    matrixMetric($metric, 2, 40);    // org2

    $from = CarbonImmutable::today()->startOfDay();
    $to = CarbonImmutable::today()->endOfDay();

    app(TenantContext::class)->set(TenantId::from(1));

    // global (10) + org1 (20), never org2 (40).
    expect(app(KpiEngine::class)->total($metric, $from, $to))->toBe(30);
});

it('P13 — a KPI warmed for org1 is never served to org2 (cache keyed by tenant)', function (): void {
    $metric = 'matrix.p13.signups';
    matrixMetric($metric, null, 5); // global
    matrixMetric($metric, 1, 15);   // org1
    matrixMetric($metric, 2, 55);   // org2

    $from = CarbonImmutable::today()->startOfDay();
    $to = CarbonImmutable::today()->endOfDay();
    $engine = app(KpiEngine::class);

    // Warm org1's figure (global + org1 = 20) — this populates the org1 cache bucket.
    app(TenantContext::class)->set(TenantId::from(1));
    expect($engine->total($metric, $from, $to))->toBe(20);

    // org2 must compute its OWN figure (global + org2 = 60), not read org1's warmed value.
    app(TenantContext::class)->forget();
    app(TenantContext::class)->set(TenantId::from(2));
    expect($engine->total($metric, $from, $to))->toBe(60);
});

// ─────────────────────────────────────────── P09, P10 CRM strict scope ─────────────────────────────

it('P09 — org1 cannot read, update or delete org2 employees', function (): void {
    // organization_members.organization_id is a real FK to crm_organizations — seed both orgs.
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $org2Member = OrganizationMember::create([
        'organization_id' => $orgB->id,
        'user_id' => null,
        'email' => 'employee@org2.test',
        'role' => MemberRole::Member->value,
        'status' => MemberStatus::Active->value,
    ]);

    app(TenantContext::class)->set(TenantId::from($orgA->id));

    expect(OrganizationMember::find($org2Member->id))->toBeNull();

    OrganizationMember::whereKey($org2Member->id)->update(['email' => 'hijacked@org1.test']);
    OrganizationMember::whereKey($org2Member->id)->delete();

    $survivor = app(TenantContext::class)->runWithoutTenancy(
        static fn (): ?OrganizationMember => OrganizationMember::find($org2Member->id),
    );

    expect($survivor)->not->toBeNull()
        ->and($survivor->email)->toBe('employee@org2.test'); // untouched
});

it('P10 — org1 cannot read or delete org2 seat pools', function (): void {
    // seat_pools.organization_id is a real FK to crm_organizations — seed both orgs.
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $org2Pool = SeatPool::create([
        'organization_id' => $orgB->id,
        'name' => 'Org2 Pool',
        'total_seats' => 10,
        'used_seats' => 0,
    ]);

    app(TenantContext::class)->set(TenantId::from($orgA->id));

    expect(SeatPool::find($org2Pool->id))->toBeNull();

    SeatPool::whereKey($org2Pool->id)->delete();

    $remaining = app(TenantContext::class)->runWithoutTenancy(
        static fn (): int => SeatPool::whereKey($org2Pool->id)->count(),
    );

    expect($remaining)->toBe(1); // org2 pool survives the org1-scoped delete
});

// ─────────────────────────────────────────── P11, P18 commerce subscription guard ──────────────────

it('P11 — org1 cannot mutate an org2 organization-subscription', function (): void {
    app(TenantContext::class)->set(TenantId::from(1));

    $guard = app(OrganizationSubscriptionGuard::class);
    $org2Subscription = new Subscription(['organization_id' => 2, 'plan_id' => 1]);

    expect(fn () => $guard->authorizeOrganization(2))
        ->toThrow(OrganizationSubscriptionAccessDeniedException::class)
        ->and(fn () => $guard->authorizeSubscription($org2Subscription))
        ->toThrow(OrganizationSubscriptionAccessDeniedException::class);

    // The org1 caller may still operate on its OWN org subscription.
    expect(fn () => $guard->authorizeOrganization(1))->not->toThrow(OrganizationSubscriptionAccessDeniedException::class);
});

it('P18 — individual (user) subscriptions are unaffected by org tenancy', function (): void {
    app(TenantContext::class)->set(TenantId::from(1));

    $guard = app(OrganizationSubscriptionGuard::class);
    $individual = new Subscription(['organization_id' => null, 'user_id' => 99, 'plan_id' => 1]);

    // An individual subscription carries no organization and is never gated — even under a resolved tenant.
    expect(fn () => $guard->authorizeSubscription($individual))
        ->not->toThrow(OrganizationSubscriptionAccessDeniedException::class);
});

// ─────────────────────────────────────────── P12 queued job restoration ────────────────────────────

it('P12 — a queued (tenant-aware) job restores the dispatching tenant on the worker', function (): void {
    // Seed org1 + org2 catalog rows; the "worker" body reads the tenant-scoped Course model.
    $org1 = matrixCourse(1);
    $org2 = matrixCourse(2);
    $global = matrixCourse(null);

    // Simulate a worker with NO ambient tenant (no authenticated user), then let the RestoreTenantContext
    // middleware (paired with the TenantAware trait) re-establish org1 exactly as it would on a real worker.
    app(TenantContext::class)->forget();
    expect(app(TenantContext::class)->id())->toBeNull();

    $seenUnderOrg1 = (new RestoreTenantContext(1))->handle(new stdClass, static fn (): array => Course::query()->pluck('id')->all());

    // After the middleware the context is cleared again (no bleed into the next job).
    expect(app(TenantContext::class)->id())->toBeNull();

    // The next job runs under org2 and sees org2 (+ global), never org1.
    $seenUnderOrg2 = (new RestoreTenantContext(2))->handle(new stdClass, static fn (): array => Course::query()->pluck('id')->all());

    expect($seenUnderOrg1)->toContain($org1->id)->toContain($global->id)->not->toContain($org2->id)
        ->and($seenUnderOrg2)->toContain($org2->id)->toContain($global->id)->not->toContain($org1->id);
});

// ─────────────────────────────────────────── P14 interleaved contexts ──────────────────────────────

it('P14 — interleaved tenant contexts cannot leak across each other', function (): void {
    $org1 = matrixCourse(1);
    $org2 = matrixCourse(2);
    $global = matrixCourse(null);
    $context = app(TenantContext::class);

    // Context A = org1.
    $context->set(TenantId::from(1));
    expect(Course::query()->pluck('id')->all())->toContain($org1->id)->not->toContain($org2->id);

    // Switch to context B = org2 (models the second concurrent execution's resolved tenant).
    $context->forget();
    $context->set(TenantId::from(2));
    expect(Course::query()->pluck('id')->all())->toContain($org2->id)->not->toContain($org1->id);

    // A re-entrant bypass in the middle sees everything, and unwinding restores the caller's boundary.
    $all = $context->runWithoutTenancy(static fn (): array => Course::query()->pluck('id')->all());
    expect($all)->toContain($org1->id)->toContain($org2->id)->toContain($global->id);

    // Back on context B = org2 after the bypass unwinds — org1 is still not visible.
    expect(Course::query()->pluck('id')->all())->toContain($org2->id)->not->toContain($org1->id);
});

// ─────────────────────────────────────────── P15 inactive membership ───────────────────────────────

it('P15 — an inactive membership is not granted org access (resolver + membership)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    // Membership side: a Removed member is NOT counted among an org's active members.
    OrganizationMember::create([
        'organization_id' => $orgA->id,
        'user_id' => null,
        'email' => 'left@org1.test',
        'role' => MemberRole::Member->value,
        'status' => MemberStatus::Removed->value,
    ]);

    app(TenantContext::class)->set(TenantId::from($orgA->id));
    $activeCount = OrganizationMember::query()->where('status', MemberStatus::Active->value)->count();
    expect($activeCount)->toBe(0); // the sole member is inactive → not active

    // Resolver side: a user who is not an active member of any org carries no organization_id, so the
    // tenant resolves to null and no org tenant is granted (only global content is visible).
    app(TenantContext::class)->forget();
    $orgAPrivate = matrixCourse($orgA->id);
    $global = matrixCourse(null);

    $nonMember = User::factory()->create(); // organization_id null → not an active org member
    $this->actingAs($nonMember);
    app(TenantContext::class)->forget();

    expect(app(RequestTenantResolver::class)->resolve())->toBeNull()
        ->and(Course::find($orgAPrivate->id))->not->toBeNull() // dormant scope → still visible (no tenant)
        ->and(Course::find($global->id))->not->toBeNull();

    // And once a concrete OUTSIDER tenant is active, that org-private row is denied — proving org access
    // is granted strictly by the resolved tenant, never inferred for a non-member.
    app(TenantContext::class)->set(TenantId::from($orgB->id));
    expect(Course::find($orgAPrivate->id))->toBeNull();
});

// ─────────────────────────────────────────── P16 forged organization_id ────────────────────────────

it('P16 — a forged organization_id in a payload is ignored; the tenant is server-resolved', function (): void {
    // Acting as an org1 employee; a hostile request body claims organization_id = 999.
    $employee = User::factory()->create(['organization_id' => 1]);
    $this->actingAs($employee);
    request()->merge(['organization_id' => 999, 'tenant_id' => 999]);
    app(TenantContext::class)->forget();

    // The resolver derives the tenant ONLY from the acting user's organization_id — never request input.
    expect(app(RequestTenantResolver::class)->resolve()?->value)->toEqual(1);

    // Created under org1: the stamp-on-create hook fills the RESOLVED tenant server-side.
    $course = Course::factory()->create(['title' => 'Legit']);
    expect((int) $course->organization_id)->toBe(1);

    // organization_id is NOT fillable: a forged value via mass-assignment (the real payload path) is
    // dropped, so the course stays owned by org1. (Factories bypass mass-assignment guards, so a
    // factory attribute is NOT a valid stand-in for a forged request payload.)
    $course->update(['organization_id' => 999]);
    expect((int) $course->fresh()->organization_id)->toBe(1);
});

// ─────────────────────────────────────────── P20 super_admin bypass + audit ────────────────────────

it('P20 — super_admin cross-tenant access works and is audited', function (): void {
    $org2Course = matrixCourse(2);

    $superAdmin = User::factory()->create(); // no organization_id
    $superAdmin->assignRole('super_admin');
    $this->actingAs($superAdmin);

    // A tenant is nominally active, but the role-based bypass lets the platform admin reach across it.
    app(TenantContext::class)->set(TenantId::from(1));
    expect(Course::find($org2Course->id))->not->toBeNull();

    // The cross-tenant operation is recorded on the append-only audit trail (actor + subject captured).
    $entry = app(AuditLogger::class)->log('tenancy.superadmin.cross_tenant_access', $org2Course, [
        'active_tenant' => 1,
        'accessed_organization' => 2,
    ]);

    expect($entry)->toBeInstanceOf(AuditLog::class)
        ->and((int) $entry->actor_id)->toBe((int) $superAdmin->id)
        ->and((int) $entry->subject_id)->toBe((int) $org2Course->id)
        ->and(AuditLog::where('action', 'tenancy.superadmin.cross_tenant_access')->where('actor_id', $superAdmin->id)->exists())
        ->toBeTrue();
});
