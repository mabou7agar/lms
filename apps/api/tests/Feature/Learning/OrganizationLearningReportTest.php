<?php

declare(strict_types=1);

use App\Contexts\Learning\Analytics\OrganizationLearningReport;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonProgress;
use App\Domains\Catalog\Models\Course;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * T1 adversarial matrix for Learning REPORTING. Learner rows are USER-OWNED / TENANT-CONSTRAINED and
 * are NEVER strict-scoped, so a learner always reads their own records (a personal B2C learner with
 * no org included). ORGANIZATION reporting is a SEPARATE projection that filters learners through
 * organization membership, so an org1 manager can never observe an org2 learner.
 */
beforeEach(function (): void {
    app(TenantContext::class)->forget();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

/**
 * Two orgs, one learner each (through active membership), plus a personal learner with NO membership.
 *
 * @return array{org1: Organization, org2: Organization, learner1: User, learner2: User, personal: User}
 */
function seedOrgLearning(): array
{
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    $course = Course::factory()->published()->create();

    $learner1 = User::factory()->create();
    $learner2 = User::factory()->create();
    $personal = User::factory()->create();

    OrganizationMember::create(['organization_id' => $org1->id, 'user_id' => $learner1->id, 'email' => $learner1->email, 'role' => 'member', 'status' => 'active']);
    OrganizationMember::create(['organization_id' => $org2->id, 'user_id' => $learner2->id, 'email' => $learner2->email, 'role' => 'member', 'status' => 'active']);

    Enrollment::factory()->create(['user_id' => $learner1->id, 'course_id' => $course->id]);
    Enrollment::factory()->completed()->create(['user_id' => $learner2->id, 'course_id' => $course->id]);
    Enrollment::factory()->create(['user_id' => $personal->id, 'course_id' => $course->id]);

    return compact('org1', 'org2', 'learner1', 'learner2', 'personal');
}

it('an org1 manager report NEVER includes an org2 learner', function (): void {
    ['org1' => $org1, 'learner1' => $learner1, 'learner2' => $learner2, 'personal' => $personal] = seedOrgLearning();

    $report = app(OrganizationLearningReport::class);

    $ids = $report->learnerIdsForOrganization($org1->id);

    expect($ids)->toContain($learner1->id)
        ->and($ids)->not->toContain($learner2->id)   // org2 learner never leaks
        ->and($ids)->not->toContain($personal->id);  // non-member never leaks

    $stats = $report->forOrganization($org1->id);
    expect($stats['learners'])->toBe(1)
        ->and($stats['enrollments'])->toBe(1)
        ->and($stats['completions'])->toBe(0);
});

it('forCurrentTenant scopes the report to the resolved tenant org only', function (): void {
    ['org1' => $org1, 'learner1' => $learner1, 'learner2' => $learner2] = seedOrgLearning();

    // The org id comes ONLY from the resolved tenant — never client input.
    app(TenantContext::class)->set(TenantId::from($org1->id));

    $report = app(OrganizationLearningReport::class);
    $stats = $report->forCurrentTenant();
    $ids = $report->learnerIdsForOrganization($org1->id);

    expect($stats['organization_id'])->toBe($org1->id)
        ->and($stats['learners'])->toBe(1)
        ->and($ids)->toContain($learner1->id)
        ->and($ids)->not->toContain($learner2->id);
});

it('org2 completions are attributed to org2, and are invisible to an org1 report', function (): void {
    ['org1' => $org1, 'org2' => $org2] = seedOrgLearning();

    $report = app(OrganizationLearningReport::class);

    expect($report->forOrganization($org2->id)['completions'])->toBe(1) // learner2 completed
        ->and($report->forOrganization($org1->id)['completions'])->toBe(0);
});

it('forCurrentTenant returns the empty envelope when no org tenant is resolved (personal/B2C)', function (): void {
    seedOrgLearning();

    // A personal learner / anonymous request has no resolved org tenant -> no manager report.
    $stats = app(OrganizationLearningReport::class)->forCurrentTenant();

    expect($stats)->toBe(['organization_id' => null, 'learners' => 0, 'enrollments' => 0, 'completions' => 0]);
});

it('a personal learner with NO org still sees their OWN enrollments and progress', function (): void {
    ['org1' => $org1, 'personal' => $personal] = seedOrgLearning();

    $ownEnrollment = Enrollment::where('user_id', $personal->id)->firstOrFail();
    LessonProgress::factory()->completed()->create(['enrollment_id' => $ownEnrollment->id]);

    // Even with a foreign org tenant active, the learner's OWN rows are NOT scoped away — self-access
    // is unaffected because the learner models carry no tenant scope.
    app(TenantContext::class)->set(TenantId::from($org1->id));

    expect(Enrollment::where('user_id', $personal->id)->exists())->toBeTrue()
        ->and(LessonProgress::where('enrollment_id', $ownEnrollment->id)->exists())->toBeTrue();

    // ...and the personal learner is absent from the org1 manager report.
    expect(app(OrganizationLearningReport::class)->learnerIdsForOrganization($org1->id))
        ->not->toContain($personal->id);
});

it('preserves Sprint 0.2 timezone day-boundary behaviour in the org report path', function (): void {
    $org = Organization::factory()->create();
    $course = Course::factory()->published()->create();
    $learner = User::factory()->create();

    OrganizationMember::create(['organization_id' => $org->id, 'user_id' => $learner->id, 'email' => $learner->email, 'role' => 'member', 'status' => 'active']);

    // 22:00 UTC on 2024-06-15 is 2024-06-16 in Riyadh (+03).
    Enrollment::factory()->create([
        'user_id' => $learner->id,
        'course_id' => $course->id,
        'enrolled_at' => CarbonImmutable::parse('2024-06-15 22:00:00', 'UTC'),
    ]);

    $report = app(OrganizationLearningReport::class);

    // UTC default is byte-for-byte the explicit-UTC path: [2024-06-15 00:00, 2024-06-16 00:00) counts it.
    expect($report->forOrganization($org->id, '2024-06-15', '2024-06-15')['enrollments'])->toBe(1)
        ->and($report->forOrganization($org->id, '2024-06-15', '2024-06-15', 'UTC')['enrollments'])->toBe(1);

    // In Riyadh the 2024-06-15 window ends 2024-06-15 21:00 UTC, so 22:00 UTC falls OUTSIDE it.
    expect($report->forOrganization($org->id, '2024-06-15', '2024-06-15', 'Asia/Riyadh')['enrollments'])->toBe(0)
        ->and($report->forOrganization($org->id, '2024-06-16', '2024-06-16', 'Asia/Riyadh')['enrollments'])->toBe(1);
});
