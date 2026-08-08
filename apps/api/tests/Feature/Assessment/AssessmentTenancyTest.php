<?php

declare(strict_types=1);

use App\Domains\Assessment\Database\Seeders\AssessmentSeeder;
use App\Domains\Assessment\Models\Assessment;
use App\Domains\Assessment\Models\AssessmentQuestion;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Support\LessonAssessmentAdapter;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * T1 Option-N ("global-OR-own-org") tenancy for the Assessment context, enforced THROUGH the owning
 * Course (assessments/assignments) and, transitively, through the owning assessment/assignment
 * (questions/options/rubrics). No tenant column exists on any Assessment table — visibility is derived
 * by CourseTenantScope joining to `courses`.
 *
 * The isolation cases drive the enforcement layer directly via TenantContext (the same pattern the
 * kernel's CrossTenantLeakageTest / SharedOrOwnedTenantScopeTest use), and the HTTP cases prove the
 * full instructor stack. Existing NULL-organization users resolve NO tenant, so the scope is dormant
 * and the pre-existing suite is unaffected.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(IdentitySeeder::class);
    $this->seed(AssessmentSeeder::class);
    app(TenantContext::class)->forget();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

/** A course optionally made private to an organization (organization_id is not fillable by design). */
function tenancyCourse(?int $organizationId = null, ?User $trainer = null): Course
{
    $course = Course::factory()->create(['status' => CourseStatus::Draft]);

    if ($organizationId !== null) {
        // forceFill: organization_id is deliberately guarded on Course so a payload can never set it;
        // the test sets it explicitly to model an org-private course.
        $course->forceFill(['organization_id' => $organizationId])->save();
    }

    if ($trainer !== null) {
        $course->syncTrainers([$trainer->id]);
    }

    return $course;
}

function tenancyInstructor(int $organizationId): User
{
    $user = User::factory()->create(['organization_id' => $organizationId]);
    $user->assignRole(SpatieRole::findByName('instructor', 'web'));

    return $user;
}

// ─────────────────────────────── enforcement layer (deterministic) ───────────────────────────────

it('hides an assessment whose course is another organization private from the active tenant', function (): void {
    $org2Course = tenancyCourse(2);
    $assessment = Assessment::factory()->create(['course_id' => $org2Course->id, 'title' => 'Secret']);

    app(TenantContext::class)->set(TenantId::from(1));

    expect(Assessment::find($assessment->id))->toBeNull()
        ->and(Assessment::where('course_id', $org2Course->id)->exists())->toBeFalse()
        ->and(Assessment::whereKey($assessment->id)->count())->toBe(0);
});

it('does not let the active tenant update another organization private-course assessment', function (): void {
    $org2Course = tenancyCourse(2);
    $assessment = Assessment::factory()->create(['course_id' => $org2Course->id, 'title' => 'Original']);

    app(TenantContext::class)->set(TenantId::from(1));
    Assessment::whereKey($assessment->id)->update(['title' => 'Hacked']);

    $title = app(TenantContext::class)->runWithoutTenancy(
        static fn (): string => (string) Assessment::findOrFail($assessment->id)->title,
    );

    expect($title)->toBe('Original');
});

it('shows the active tenant assessments on global and own-org courses, never another org', function (): void {
    $globalCourse = tenancyCourse(null);
    $org1Course = tenancyCourse(1);
    $org2Course = tenancyCourse(2);

    Assessment::factory()->create(['course_id' => $globalCourse->id, 'title' => 'g']);
    Assessment::factory()->create(['course_id' => $org1Course->id, 'title' => 'o1']);
    Assessment::factory()->create(['course_id' => $org2Course->id, 'title' => 'o2']);

    app(TenantContext::class)->set(TenantId::from(1));

    $visible = Assessment::whereIn('course_id', [$globalCourse->id, $org1Course->id, $org2Course->id])
        ->orderBy('title')->pluck('title')->all();

    expect($visible)->toBe(['g', 'o1']);
});

it('hides an assignment whose course is another organization private, keeps own + global', function (): void {
    $globalCourse = tenancyCourse(null);
    $org1Course = tenancyCourse(1);
    $org2Course = tenancyCourse(2);

    $global = Assignment::factory()->create(['course_id' => $globalCourse->id]);
    $own = Assignment::factory()->create(['course_id' => $org1Course->id]);
    $foreign = Assignment::factory()->create(['course_id' => $org2Course->id]);

    app(TenantContext::class)->set(TenantId::from(1));

    expect(Assignment::find($foreign->id))->toBeNull()
        ->and(Assignment::find($own->id))->not->toBeNull()
        ->and(Assignment::find($global->id))->not->toBeNull();
});

it('makes questions inherit their parent assessment tenancy through the parent, with no child column', function (): void {
    $org2Course = tenancyCourse(2);
    $assessment = Assessment::factory()->create(['course_id' => $org2Course->id]);
    $question = AssessmentQuestion::factory()->create(['assessment_id' => $assessment->id]);

    // Foreign tenant: the child row is addressable by id (it carries no tenant column) but its
    // authorization anchor — the parent assessment — is invisible, so nothing can be managed through it.
    app(TenantContext::class)->set(TenantId::from(1));
    expect(AssessmentQuestion::find($question->id)?->assessment)->toBeNull();

    // Owning tenant: the parent resolves normally.
    app(TenantContext::class)->forget();
    app(TenantContext::class)->set(TenantId::from(2));
    expect(AssessmentQuestion::find($question->id)?->assessment?->id)->toBe($assessment->id);
});

it('rejects attaching an assessment whose course is invisible to the active tenant', function (): void {
    $org2Course = tenancyCourse(2);
    $assessment = Assessment::factory()->published()->create(['course_id' => $org2Course->id]);

    app(TenantContext::class)->set(TenantId::from(1));

    // Cross-tenant attach (assessment <-> lesson): the port lookup rides Assessment's tenant scope,
    // so an org1 caller cannot resolve an org2-private-course assessment for attachment.
    expect(app(LessonAssessmentAdapter::class)->resolveAttachable($assessment->public_id, $org2Course->id))
        ->toBeNull();
});

it('allows attaching an assessment on the active tenant own-org course', function (): void {
    $org1Course = tenancyCourse(1);
    $assessment = Assessment::factory()->published()->create(['course_id' => $org1Course->id]);

    app(TenantContext::class)->set(TenantId::from(1));

    $ref = app(LessonAssessmentAdapter::class)->resolveAttachable($assessment->public_id, $org1Course->id);

    expect($ref)->not->toBeNull()
        ->and($ref->id)->toBe($assessment->id);
});

it('keeps the same-course guard: a global assessment cannot attach onto another org private course', function (): void {
    $globalCourse = tenancyCourse(null);
    $org2Course = tenancyCourse(2);
    $globalAssessment = Assessment::factory()->published()->create(['course_id' => $globalCourse->id]);

    // Even with tenancy bypassed the mismatched course_id is refused (the global assessment does not
    // belong to the org2 course), so cross-tenant attachment cannot be forced through a shared row.
    app(TenantContext::class)->runWithoutTenancy(function () use ($globalAssessment, $org2Course): void {
        expect(app(LessonAssessmentAdapter::class)->resolveAttachable($globalAssessment->public_id, $org2Course->id))
            ->toBeNull();
    });
});

it('is dormant with no resolved tenant: assessments on any course stay globally visible', function (): void {
    $globalCourse = tenancyCourse(null);
    $org2Course = tenancyCourse(2);
    Assessment::factory()->create(['course_id' => $globalCourse->id]);
    Assessment::factory()->create(['course_id' => $org2Course->id]);

    app(TenantContext::class)->forget();

    expect(Assessment::whereIn('course_id', [$globalCourse->id, $org2Course->id])->count())->toBe(2);
});

// ─────────────────────────────────────── HTTP instructor stack ───────────────────────────────────

it('lets an org instructor manage an assessment on their own org-private course', function (): void {
    $instructor = tenancyInstructor(101);
    $course = tenancyCourse(101, $instructor);
    $assessment = Assessment::factory()->create(['course_id' => $course->id, 'title' => 'Own']);

    $this->actingAs($instructor, 'sanctum');
    app(TenantContext::class)->forget();

    $this->putJson("/api/v1/admin/assessments/{$assessment->public_id}", ['title' => 'Renamed'])
        ->assertOk();

    $title = app(TenantContext::class)->runWithoutTenancy(
        static fn (): string => (string) Assessment::findOrFail($assessment->id)->title,
    );

    expect($title)->toBe('Renamed');
});

it('lets an org instructor manage assessments on a GLOBAL course they train', function (): void {
    $instructor = tenancyInstructor(101);
    $globalCourse = tenancyCourse(null, $instructor);

    $this->actingAs($instructor, 'sanctum');
    app(TenantContext::class)->forget();

    // Store path resolves the global course under the active tenant (organization_id IS NULL is
    // visible to every tenant) and the created assessment stays global (no tenant column).
    $this->postJson("/api/v1/admin/courses/{$globalCourse->public_id}/assessments", ['title' => 'Quiz'])
        ->assertCreated();

    $count = app(TenantContext::class)->runWithoutTenancy(
        static fn (): int => Assessment::where('course_id', $globalCourse->id)->count(),
    );

    expect($count)->toBe(1);
});

it('hides an org2-private-course assessment from an org1 instructor behind a 404', function (): void {
    $attacker = tenancyInstructor(101);
    $org2Course = tenancyCourse(202);
    $assessment = Assessment::factory()->create(['course_id' => $org2Course->id, 'title' => 'Secret']);

    $this->actingAs($attacker, 'sanctum');
    app(TenantContext::class)->forget();

    // Invisible, not merely forbidden: route-model binding is tenant-scoped, so the row cannot be
    // reached at all — mirroring the existing "course you do not train" 404 semantics.
    $this->getJson("/api/v1/admin/assessments/{$assessment->public_id}")->assertNotFound();
    $this->putJson("/api/v1/admin/assessments/{$assessment->public_id}", ['title' => 'Hijacked'])->assertNotFound();

    $title = app(TenantContext::class)->runWithoutTenancy(
        static fn (): string => (string) Assessment::findOrFail($assessment->id)->title,
    );

    expect($title)->toBe('Secret');
});

it('refuses to attach a question to an org2-private-course assessment for an org1 instructor', function (): void {
    $attacker = tenancyInstructor(101);
    $org2Course = tenancyCourse(202);
    $assessment = Assessment::factory()->create(['course_id' => $org2Course->id]);

    $this->actingAs($attacker, 'sanctum');
    app(TenantContext::class)->forget();

    // Child (question) inheritance at the HTTP layer: the parent assessment cannot be bound, so the
    // question route 404s before any write.
    $this->postJson("/api/v1/admin/assessments/{$assessment->public_id}/questions", [
        'type' => 'single_choice',
        'prompt' => '<p>Capital of France?</p>',
        'options' => [
            ['label' => 'Paris', 'is_correct' => true],
            ['label' => 'Berlin', 'is_correct' => false],
        ],
    ])->assertNotFound();

    $count = app(TenantContext::class)->runWithoutTenancy(
        static fn (): int => $assessment->questions()->count(),
    );

    expect($count)->toBe(0);
});

it('hides an org2-private-course assignment from an org1 instructor behind a 404', function (): void {
    $attacker = tenancyInstructor(101);
    $org2Course = tenancyCourse(202);
    $assignment = Assignment::factory()->create(['course_id' => $org2Course->id]);

    $this->actingAs($attacker, 'sanctum');
    app(TenantContext::class)->forget();

    $this->getJson("/api/v1/admin/assignments/{$assignment->public_id}")->assertNotFound();
    $this->putJson("/api/v1/admin/assignments/{$assignment->public_id}", ['title' => 'Hijacked'])->assertNotFound();
});

it('refuses to build a rubric on an org2-private-course assignment for an org1 instructor', function (): void {
    $attacker = tenancyInstructor(101);
    $org2Course = tenancyCourse(202);
    $assignment = Assignment::factory()->create(['course_id' => $org2Course->id]);

    $this->actingAs($attacker, 'sanctum');
    app(TenantContext::class)->forget();

    // Rubric <-> assignment attach: the parent assignment cannot be bound cross-tenant, so the rubric
    // route 404s before any write (rubric criteria/levels inherit tenancy through the assignment).
    $this->putJson("/api/v1/admin/assignments/{$assignment->public_id}/rubric", [])
        ->assertNotFound();
});

it('leaves a global-course assessment fully manageable for a NULL-organization instructor (backward compatible)', function (): void {
    // No organization_id on the user => no tenant resolves => scope dormant => unchanged behaviour.
    $instructor = User::factory()->create();
    $instructor->assignRole(SpatieRole::findByName('instructor', 'web'));
    $course = tenancyCourse(null, $instructor);
    $assessment = Assessment::factory()->create(['course_id' => $course->id, 'title' => 'Global']);

    $this->actingAs($instructor, 'sanctum')
        ->putJson("/api/v1/admin/assessments/{$assessment->public_id}", ['title' => 'Edited'])
        ->assertOk();

    expect($assessment->refresh()->title)->toBe('Edited');
});
