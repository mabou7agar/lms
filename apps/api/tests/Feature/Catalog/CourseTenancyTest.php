<?php

declare(strict_types=1);

use App\Domains\Catalog\Actions\Category\CreateCategoryAction;
use App\Domains\Catalog\Actions\Course\CreateCourseAction;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * T1 Option-N ("global-OR-own-org") adversarial matrix for the Catalog roots (Course + Category).
 *
 * These are the ONLY Catalog tests that establish a tenant context; the existing suite runs with
 * NULL-org users, so SharedOrOwnedTenantScope no-ops there and those tests stay behaviourally
 * identical. Isolation is asserted at both the model and the HTTP boundary. The tenant is only ever
 * derived server-side (resolved TenantContext) — never from client input.
 */
beforeEach(function (): void {
    app(TenantContext::class)->forget();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

/** Create a course owned by $org (null = global platform content), stamped server-side by the trait. */
function courseForOrg(?int $org, array $attrs = []): Course
{
    $context = app(TenantContext::class);

    if ($org === null) {
        $context->forget();

        return Course::factory()->create($attrs);
    }

    $context->set(TenantId::from($org));
    $course = Course::factory()->create($attrs);
    $context->forget();

    return $course;
}

// ---------------------------------------------------------------------------------------------
// Backward compatibility: anonymous / NULL-tenant behaviour is unchanged.
// ---------------------------------------------------------------------------------------------

it('leaves catalog reads unscoped when no tenant is resolved (existing behaviour)', function (): void {
    courseForOrg(null);
    courseForOrg(1);
    courseForOrg(2);

    // No tenant context -> SharedOrOwnedTenantScope no-ops -> every row is visible, exactly as before.
    expect(Course::count())->toBe(3);
});

it('shows the global public catalog to an anonymous listing request (HTTP, unchanged)', function (): void {
    courseForOrg(null, ['title' => 'Global Published Course', 'status' => 'published', 'published_at' => now()]);

    $res = $this->getJson('/api/v1/courses')->assertOk();

    expect(collect($res->json('data'))->pluck('title'))->toContain('Global Published Course');
});

// ---------------------------------------------------------------------------------------------
// Isolation: an org sees global + its own private rows, never another org's private rows.
// ---------------------------------------------------------------------------------------------

it('shows an org1 user the global catalog PLUS org1-private courses, never org2-private (model boundary)', function (): void {
    courseForOrg(null, ['title' => 'Global']);
    courseForOrg(1, ['title' => 'Org1 Private']);
    courseForOrg(2, ['title' => 'Org2 Private']);

    app(TenantContext::class)->set(TenantId::from(1));

    $visible = Course::orderBy('title')->pluck('title')->all();

    expect($visible)->toBe(['Global', 'Org1 Private'])
        ->and(Course::where('title', 'Org2 Private')->exists())->toBeFalse();
});

it('never leaks an org2-private course to an org1 listing request (HTTP boundary)', function (): void {
    courseForOrg(null, ['title' => 'Global Course', 'status' => 'published', 'published_at' => now()]);
    courseForOrg(1, ['title' => 'Org1 Course', 'status' => 'published', 'published_at' => now()]);
    courseForOrg(2, ['title' => 'Org2 Course', 'status' => 'published', 'published_at' => now()]);

    // Resolve the request to org1 (equivalent to an authenticated org1 employee; ResolveTenant only
    // overrides an EMPTY context, so this explicit set survives the request pipeline).
    app(TenantContext::class)->set(TenantId::from(1));

    $titles = collect($this->getJson('/api/v1/courses')->assertOk()->json('data'))->pluck('title');

    expect($titles)->toContain('Global Course')
        ->and($titles)->toContain('Org1 Course')
        ->and($titles)->not->toContain('Org2 Course');
});

it('keeps the global catalog visible to a resolved tenant', function (): void {
    $global = courseForOrg(null, ['title' => 'Global Only']);

    app(TenantContext::class)->set(TenantId::from(1));

    expect(Course::find($global->id))->not->toBeNull()
        ->and(Course::find($global->id)->isGlobal())->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Server-side stamping + forged-tenant defense.
// ---------------------------------------------------------------------------------------------

it('stamps organization_id from the resolved tenant when an org1 user creates a course (server-side)', function (): void {
    $employee = User::factory()->create(['organization_id' => 1]);
    $this->actingAs($employee);
    app(TenantContext::class)->forget(); // re-arm lazy resolution now that the user is acting

    $course = app(CreateCourseAction::class)->execute(['title' => 'Authored By Org1']);

    expect((int) $course->organization_id)->toBe(1)
        ->and($course->belongsToTenant(TenantId::from(1)))->toBeTrue();
});

it('ignores a forged organization_id in the create payload and stamps the real tenant instead', function (): void {
    app(TenantContext::class)->set(TenantId::from(1));

    // Forge org2 in the payload two ways: raw mass-assignment AND through the create action.
    $viaModel = Course::create([
        'title' => 'Forged Via Model',
        'slug' => 'forged-via-model',
        'organization_id' => 2, // not fillable -> dropped -> trait stamps the real tenant (org1)
        'tenant_id' => 2,
    ]);

    $viaAction = app(CreateCourseAction::class)->execute([
        'title' => 'Forged Via Action',
        'organization_id' => 2, // the action never reads this key
    ]);

    expect((int) $viaModel->organization_id)->toBe(1)
        ->and((int) $viaAction->organization_id)->toBe(1);
});

it('creates a GLOBAL course (organization_id NULL) when no tenant is resolved even if a payload forges one', function (): void {
    app(TenantContext::class)->forget();

    $course = Course::create([
        'title' => 'Anon Forged',
        'slug' => 'anon-forged',
        'organization_id' => 2, // ignored: not fillable, and no tenant to stamp
    ]);

    expect($course->organization_id)->toBeNull()
        ->and($course->isGlobal())->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Categories (matrix: SHARED-OR-OWNED/NULLABLE — org-private categories approved).
// ---------------------------------------------------------------------------------------------

it('scopes categories global-OR-own-org and ignores a forged organization_id on create', function (): void {
    // Seed a global + an org2-private category without a resolved tenant leaking in.
    app(TenantContext::class)->runWithoutTenancy(function (): void {
        Category::factory()->create(['name' => 'Global Cat']);
        $org2 = Category::factory()->create(['name' => 'Org2 Cat']);
        $org2->forceFill(['organization_id' => 2])->save();
    });

    app(TenantContext::class)->set(TenantId::from(1));

    // A forged org2 id in the category payload is dropped; the trait stamps the resolved tenant (org1).
    $created = app(CreateCategoryAction::class)->execute(['name' => 'Org1 Cat', 'organization_id' => 2]);

    expect((int) $created->organization_id)->toBe(1)
        ->and(Category::orderBy('name')->pluck('name')->all())->toBe(['Global Cat', 'Org1 Cat'])
        ->and(Category::where('name', 'Org2 Cat')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Trainers/instructors remain course-authorization-bound under tenancy.
// ---------------------------------------------------------------------------------------------

it('hides another org private course from an org1 user, so its instructors cannot be managed', function (): void {
    $org2Course = courseForOrg(2, ['title' => 'Org2 Only']);

    // Under org1's resolved context the org2-private course is invisible: route-model binding /
    // Course::find returns null, so an org1 actor can never obtain it to pass to CourseInstructorService.
    app(TenantContext::class)->set(TenantId::from(1));

    expect(Course::find($org2Course->id))->toBeNull()
        ->and(Course::where('title', 'Org2 Only')->first())->toBeNull();
});
