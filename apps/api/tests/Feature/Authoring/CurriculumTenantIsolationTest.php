<?php

use App\Domains\Authoring\Database\Seeders\AuthoringSeeder;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Authoring\Services\ContentVersioningService;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * T1 (Option N — "global-OR-own-org") tenant isolation for AUTHORING, enforced TRANSITIVELY through
 * the parent Course. A resolved tenant may reach a GLOBAL course or its OWN org-private course, but
 * NEVER another organization's private course — for every curriculum read, mutation, duplication,
 * reorder, versioning and preview path. Mirrors the InstructorCurriculumAccessTest style.
 *
 * The adversarial construction deliberately makes the intruder an assigned TRAINER on the target
 * course, so the ONLY thing that can deny them is the tenant boundary (not the ownership/trainer
 * rule). That isolates the new tenant dimension from the pre-existing course-ownership gate.
 *
 * Dependency: enforcement requires the shared Catalog `courses.organization_id` column (added when
 * Course adopts BelongsToTenantNullable — Kernel/Catalog wiring by another agent). Until it lands
 * there is no way to mark a course org-private, so there is nothing to isolate and the suite skips
 * (staying green throughout the staged rollout). Once the column exists, the gate enforces the
 * boundary even before the trait's query-scope lands, because the enforcement helper reads the
 * column directly.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    if (! Schema::hasColumn('courses', 'organization_id')) {
        $this->markTestSkipped('courses.organization_id not present yet (Catalog BelongsToTenantNullable adoption pending).');
    }

    $this->seed(RolePermissionSeeder::class);
    // AuthoringSeeder grants authoring.curriculum.manage to ADMIN only; instructors get access solely
    // from trainer ownership — exactly the dimension these tests hold constant while varying tenancy.
    $this->seed(AuthoringSeeder::class);
    config()->set('authoring.blocks_enabled', true);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** An instructor optionally bound to an organization (its active tenant once authenticated). */
if (! function_exists('tenIso_instructor')) {
    function tenIso_instructor(?int $orgId): User
    {
        $user = User::factory()->create($orgId === null ? [] : ['organization_id' => $orgId]);
        // Web-guard role model, robust to Sanctum's guard switch (matches the sibling curriculum tests).
        $user->assignRole(SpatieRole::findByName('instructor', 'web'));

        return $user;
    }
}

/**
 * A course optionally made private to an organization. `organization_id` is server-controlled — never
 * fillable, never from client input — so it is stamped directly the way production creation would,
 * without model events or the tenant scope. ALWAYS called before any tenant is active, so the course
 * starts as GLOBAL and is then (optionally) marked private.
 */
if (! function_exists('tenIso_course')) {
    function tenIso_course(?int $orgId, ?User $trainer = null, CourseStatus $status = CourseStatus::Draft): Course
    {
        $course = Course::factory()->create(['status' => $status]);

        if ($orgId !== null) {
            $course->forceFill(['organization_id' => $orgId])->saveQuietly();
        }

        if ($trainer !== null) {
            $course->syncTrainers([$trainer->id]);
        }

        return $course;
    }
}

/** Authenticate + re-arm lazy tenant resolution so the request resolves the user's org as the tenant. */
if (! function_exists('tenIso_actAs')) {
    function tenIso_actAs(User $user): void
    {
        Sanctum::actingAs($user);
        app(TenantContext::class)->forget();
    }
}

// ── 1. Cross-tenant curriculum (read/edit/reorder/duplicate) is denied on an org2 PRIVATE course ──
it('denies an org1 instructor every curriculum path on an org2 PRIVATE course, even as its trainer', function () {
    $instructor = tenIso_instructor(9001);
    // Intruder is (adversarially) a trainer on the org2-private course, so ONLY tenancy can deny them.
    $course = tenIso_course(9002, trainer: $instructor);
    $section = Section::factory()->create(['course_id' => $course->id, 'title' => 'original']);
    $lesson = Lesson::factory()->create(['section_id' => $section->id]);
    $block = Block::factory()->for($lesson)->create(['type' => 'article', 'position' => 0]);

    tenIso_actAs($instructor);

    // Course-bound paths: invisible => 403 (gate) or 404 (scoped model binding) — both mean "denied".
    expect($this->getJson("/api/v1/admin/courses/{$course->public_id}/curriculum")->status())->toBeIn([403, 404]);
    expect($this->postJson("/api/v1/admin/courses/{$course->public_id}/sections", ['title' => 'x'])->status())->toBeIn([403, 404]);
    expect($this->putJson("/api/v1/admin/courses/{$course->public_id}/sections/order", ['order' => [$section->public_id]])->status())->toBeIn([403, 404]);
    expect($this->postJson("/api/v1/admin/courses/{$course->public_id}/sections/{$section->public_id}/duplicate")->status())->toBeIn([403, 404]);

    // Child-bound paths always reach the gate via the parent course => a consistent 403.
    $this->putJson("/api/v1/admin/sections/{$section->public_id}", ['title' => 'hacked'])->assertForbidden();
    $this->deleteJson("/api/v1/admin/sections/{$section->public_id}")->assertForbidden();
    $this->postJson("/api/v1/admin/sections/{$section->public_id}/publish", ['state' => 'published'])->assertForbidden();
    $this->putJson("/api/v1/admin/lessons/{$lesson->public_id}", ['title' => 'hacked'])->assertForbidden();
    $this->deleteJson("/api/v1/admin/lessons/{$lesson->public_id}")->assertForbidden();
    $this->postJson("/api/v1/admin/lessons/{$lesson->public_id}/preview")->assertForbidden();
    $this->postJson("/api/v1/admin/sections/{$section->public_id}/lessons", ['title' => 'x', 'type' => 'article'])->assertForbidden();
    $this->putJson("/api/v1/admin/blocks/{$block->public_id}", ['content_i18n' => ['en' => ['html' => '<p>x</p>']]])->assertForbidden();
    $this->deleteJson("/api/v1/admin/blocks/{$block->public_id}")->assertForbidden();

    // Nothing was mutated.
    expect($section->fresh()->title)->toBe('original');
});

// ── 2. Cross-tenant versioning is denied, and forking INTO an org2 private course is blocked ──────
it('denies an org1 instructor versioning paths on an org2 PRIVATE course', function () {
    $orgTwoTrainer = tenIso_instructor(9002);
    $course = tenIso_course(9002, trainer: $orgTwoTrainer);
    Section::factory()->create(['course_id' => $course->id]);

    // A pre-existing version, created out of band (bypass tenancy for a deterministic fixture).
    $version = app(TenantContext::class)->runWithoutTenancy(
        fn () => app(ContentVersioningService::class)->createSnapshot((int) $course->id, (int) $orgTwoTrainer->id, null, false),
    );

    // The intruder: an org1 instructor who is ALSO (adversarially) a trainer on the org2 course.
    $intruder = tenIso_instructor(9001);
    $course->syncTrainers([$orgTwoTrainer->id, $intruder->id]);
    tenIso_actAs($intruder);

    // Course-scoped version list/create resolve the course via CourseAccessPort => invisible => 404.
    $this->getJson("/api/v1/admin/courses/{$course->public_id}/versions")->assertNotFound();
    $this->postJson("/api/v1/admin/courses/{$course->public_id}/versions")->assertNotFound();

    // Version-bound ops authorize through the version's course (ContentVersionPolicy) => 403.
    $this->getJson("/api/v1/admin/versions/{$version->public_id}")->assertForbidden();
    $this->postJson("/api/v1/admin/versions/{$version->public_id}/clone")->assertForbidden();
    $this->postJson("/api/v1/admin/versions/{$version->public_id}/restore")->assertForbidden();
    $this->postJson("/api/v1/admin/versions/{$version->public_id}/rollback")->assertForbidden();
});

it('cannot fork a version into another org\'s PRIVATE course', function () {
    $instructor = tenIso_instructor(9001);
    $globalCourse = tenIso_course(null, trainer: $instructor); // org1 manages this GLOBAL course
    Section::factory()->create(['course_id' => $globalCourse->id]);

    $version = app(TenantContext::class)->runWithoutTenancy(
        fn () => app(ContentVersioningService::class)->createSnapshot((int) $globalCourse->id, (int) $instructor->id, null, false),
    );

    // Destination is org2-private; the intruder is even a trainer on it — only tenancy stops the fork.
    $orgTwoPrivate = tenIso_course(9002, trainer: $instructor);

    tenIso_actAs($instructor);

    // Source is visible (global) so `view` passes; the DESTINATION course is invisible => 404.
    $this->postJson("/api/v1/admin/versions/{$version->public_id}/fork", [
        'destination_course_id' => $orgTwoPrivate->public_id,
    ])->assertNotFound();
});

// ── 3. An org1 instructor CAN manage a GLOBAL course, exactly as today ────────────────────────────
it('lets an org1 instructor manage curriculum on a GLOBAL course', function () {
    $instructor = tenIso_instructor(9001);
    $course = tenIso_course(null, trainer: $instructor); // organization_id NULL = global catalog

    tenIso_actAs($instructor);

    $this->getJson("/api/v1/admin/courses/{$course->public_id}/curriculum")->assertOk();

    $sectionId = (string) $this->postJson("/api/v1/admin/courses/{$course->public_id}/sections", ['title' => 'Intro'])
        ->assertSuccessful()->json('data.id');
    $this->putJson("/api/v1/admin/sections/{$sectionId}", ['title' => 'Intro (edited)'])->assertSuccessful();

    $lessonId = (string) $this->postJson("/api/v1/admin/sections/{$sectionId}/lessons", ['title' => 'L1', 'type' => 'article'])
        ->assertSuccessful()->json('data.id');
    $this->putJson("/api/v1/admin/lessons/{$lessonId}", ['title' => 'L1 (edited)'])->assertSuccessful();
});

// ── 4. An org1 instructor CAN manage its OWN org-private course ────────────────────────────────────
it('lets an org1 instructor manage curriculum on its OWN org-private course', function () {
    $instructor = tenIso_instructor(9001);
    $course = tenIso_course(9001, trainer: $instructor); // private to org1 = the active tenant

    tenIso_actAs($instructor);

    $this->getJson("/api/v1/admin/courses/{$course->public_id}/curriculum")->assertOk();

    $sectionId = (string) $this->postJson("/api/v1/admin/courses/{$course->public_id}/sections", ['title' => 'Intro'])
        ->assertSuccessful()->json('data.id');
    $this->putJson("/api/v1/admin/sections/{$sectionId}", ['title' => 'edited'])->assertSuccessful();
});

// ── 5. Symmetric isolation: each org manages only its own org-private course ──────────────────────
it('isolates two orgs so each instructor manages only its own org-private course', function () {
    $orgOne = tenIso_instructor(9001);
    $orgTwo = tenIso_instructor(9002);
    $courseOne = tenIso_course(9001, trainer: $orgOne);
    $courseTwo = tenIso_course(9002, trainer: $orgTwo);

    tenIso_actAs($orgOne);
    $this->getJson("/api/v1/admin/courses/{$courseOne->public_id}/curriculum")->assertOk();
    expect($this->getJson("/api/v1/admin/courses/{$courseTwo->public_id}/curriculum")->status())->toBeIn([403, 404]);

    tenIso_actAs($orgTwo);
    $this->getJson("/api/v1/admin/courses/{$courseTwo->public_id}/curriculum")->assertOk();
    expect($this->getJson("/api/v1/admin/courses/{$courseOne->public_id}/curriculum")->status())->toBeIn([403, 404]);
});

// ── 6. Backward compatibility: a NULL-org user resolves no tenant => enforcement is a no-op ───────
it('does not scope curriculum for a NULL-org user (backward-compatible no-op)', function () {
    $instructor = tenIso_instructor(null); // no organization => no active tenant resolves
    // Even an org2-private course: with no tenant resolved, access is governed purely by trainer
    // ownership, exactly as it was before T1. This proves existing NULL-org users are unaffected.
    $course = tenIso_course(9002, trainer: $instructor);

    tenIso_actAs($instructor);

    $this->getJson("/api/v1/admin/courses/{$course->public_id}/curriculum")->assertOk();
    $this->postJson("/api/v1/admin/courses/{$course->public_id}/sections", ['title' => 'ok'])->assertSuccessful();
});

// ── 7. A forged organization_id in the request body is ignored (tenant only from the user's org) ──
it('ignores a forged organization_id in the request body', function () {
    $instructor = tenIso_instructor(9001);
    $orgTwoCourse = tenIso_course(9002, trainer: $instructor);

    tenIso_actAs($instructor);

    // Attempting to "claim" org2 in the payload must not widen visibility — tenant is derived only
    // from the authenticated user's organization_id, never from client input.
    expect(
        $this->postJson("/api/v1/admin/courses/{$orgTwoCourse->public_id}/sections", [
            'title' => 'x',
            'organization_id' => 9002,
        ])->status()
    )->toBeIn([403, 404]);
});
