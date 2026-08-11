<?php

declare(strict_types=1);

use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LearningSession;
use App\Contexts\Learning\Models\LessonVideoProgress;
use App\Domains\Assessment\Models\AssessmentAttempt;
use App\Domains\Catalog\Models\Course;
use App\Domains\Certification\Models\Certificate;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Enterprise\Contracts\ManagerReportPort;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => app(TenantContext::class)->forget());
afterEach(fn () => app(TenantContext::class)->forget());

function activeMember(Organization $org, User $user): OrganizationMember
{
    return OrganizationMember::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'email' => $user->email,
        'role' => 'member',
        'status' => 'active',
    ]);
}

it('returns every required metric and never includes another org\'s learner', function (): void {
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    $course = Course::factory()->published()->create();

    $l1 = User::factory()->create();
    $l2 = User::factory()->create();
    $other = User::factory()->create();

    activeMember($org1, $l1);
    activeMember($org1, $l2);
    activeMember($org2, $other);

    // l1: in progress (40%), recent activity, a FAILED assessment, watched 120s.
    $e1 = Enrollment::factory()->create(['user_id' => $l1->id, 'course_id' => $course->id, 'progress_percentage' => 40]);
    LessonVideoProgress::create(['enrollment_id' => $e1->id, 'user_id' => $l1->id, 'lesson_id' => 1, 'watched_seconds' => 120]);
    LearningSession::create(['user_id' => $l1->id, 'course_id' => $course->id, 'last_activity_at' => now()]);
    AssessmentAttempt::factory()->graded()->create(['user_id' => $l1->id, 'passed' => false]);

    // l2: completed (100%), NO recent activity (inactive), a PASSED assessment, a certificate.
    Enrollment::factory()->completed()->create(['user_id' => $l2->id, 'course_id' => $course->id, 'progress_percentage' => 100]);
    AssessmentAttempt::factory()->graded()->create(['user_id' => $l2->id, 'passed' => true]);
    Certificate::factory()->create(['user_id' => $l2->id, 'course_id' => $course->id]);

    // other org's learner — must never surface in org1's report.
    Enrollment::factory()->create(['user_id' => $other->id, 'course_id' => $course->id, 'progress_percentage' => 50]);

    $report = app(ManagerReportPort::class)->report(
        organizationId: $org1->id,
        userIds: null,
        inactiveDays: 7,
        seatUsage: ['purchased' => 10, 'used' => 2, 'available' => 8],
    )->toArray();

    expect($report['organization_id'])->toBe($org1->id)
        ->and($report['learners'])->toBe(2)
        ->and($report['enrollments'])->toBe(2)          // other-org enrollment excluded
        ->and($report['started'])->toBe(2)
        ->and($report['completions'])->toBe(1)
        ->and($report['avg_progress'])->toBe(70.0)
        ->and($report['watch_time_seconds'])->toBe(120)
        ->and($report['avg_watch_time_seconds_per_learner'])->toBe(60)
        ->and($report['inactive_learners'])->toBe(1)    // l2 has no recent session
        ->and($report['assessments_passed'])->toBe(1)
        ->and($report['assessments_failed'])->toBe(1)
        ->and($report['certificates_issued'])->toBe(1)
        ->and($report['seats'])->toBe(['purchased' => 10, 'used' => 2, 'available' => 8]);
});

it('a department-scoped call reports only the requested learner subset', function (): void {
    $org = Organization::factory()->create();
    $course = Course::factory()->published()->create();

    $inScope = User::factory()->create();
    $outScope = User::factory()->create();
    activeMember($org, $inScope);
    activeMember($org, $outScope);

    Enrollment::factory()->create(['user_id' => $inScope->id, 'course_id' => $course->id, 'progress_percentage' => 20]);
    Enrollment::factory()->create(['user_id' => $outScope->id, 'course_id' => $course->id, 'progress_percentage' => 90]);

    $report = app(ManagerReportPort::class)->report($org->id, [$inScope->id])->toArray();

    expect($report['learners'])->toBe(1)
        ->and($report['enrollments'])->toBe(1)
        ->and($report['avg_progress'])->toBe(20.0);
});
