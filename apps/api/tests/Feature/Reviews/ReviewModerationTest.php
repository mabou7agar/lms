<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Course;
use App\Domains\Reviews\Models\CourseReview;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Enums\Role;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Filament\Resources\ModerationQueueResource;
use App\Platform\Shared\Moderation\Enums\ReportReason;
use App\Platform\Shared\Moderation\Enums\ReportStatus;
use App\Platform\Shared\Moderation\Models\ContentReport;
use App\Platform\Shared\Moderation\Services\ModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

function reportedReview(): CourseReview
{
    $course = Course::factory()->published()->create();
    $review = CourseReview::factory()->create([
        'course_id' => $course->id,
        'user_id' => User::factory()->create()->id,
    ]);

    $review->report(User::factory()->create()->id, ReportReason::Spam, 'Please review.');

    return $review;
}

it('surfaces a reported review in the moderation queue and lets a moderator resolve it', function (): void {
    $review = reportedReview();

    // Appears in the queue as a pending report.
    $report = ContentReport::query()->firstWhere('reportable_id', $review->id);
    expect($report)->not->toBeNull()
        ->and($report->status)->toBe(ReportStatus::Pending)
        ->and($report->reportable_type)->toBe(CourseReview::class);

    // A moderator resolves it.
    $admin = User::factory()->create();
    $admin->assignRole(SpatieRole::findByName(Role::Admin->value, 'web'));

    app(ModerationService::class)->resolve($report, $admin->id);

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::Reviewed)
        ->and((int) $report->resolved_by)->toBe($admin->id)
        ->and($report->resolved_at)->not->toBeNull();
});

it('gates the moderation queue resource to moderators only', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(SpatieRole::findByName(Role::Admin->value, 'web'));
    $learner = User::factory()->create();

    $this->actingAs($admin);
    expect(ModerationQueueResource::canViewAny())->toBeTrue();

    $this->actingAs($learner);
    expect(ModerationQueueResource::canViewAny())->toBeFalse();
});
