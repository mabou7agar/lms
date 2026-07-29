<?php

use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Enums\Role;
use App\Platform\Identity\Models\User;
use App\Platform\Notifications\Actions\BulkNotificationAction;
use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Notifications\Jobs\FanOutNotificationJob;
use App\Platform\Notifications\Models\Notification;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

/** @return array{0: User, 1: Course} */
function fanoutInstructorCourse(int $students): array
{
    $instructor = User::factory()->create();
    $instructor->assignRole(SpatieRole::findByName(Role::Instructor->value, 'web'));

    $course = Course::factory()->published()->create();
    $course->syncTrainers([$instructor->id]);

    for ($i = 0; $i < $students; $i++) {
        Enrollment::factory()->create(['user_id' => User::factory()->create()->id, 'course_id' => $course->id]);
    }

    return [$instructor, $course];
}

it('returns immediately and queues a batched fan-out instead of sending inline (H4)', function () {
    Bus::fake();
    [$instructor, $course] = fanoutInstructorCourse(3);
    Sanctum::actingAs($instructor);

    $this->postJson("/api/v1/teach/courses/{$course->public_id}/announcements", [
        'title' => 'Exam moved', 'body' => 'Please read the update.',
    ])->assertCreated();

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->first() instanceof FanOutNotificationJob
        && str_starts_with((string) $batch->name, 'course-announcement:'));
});

it('chunks recipients by the configured size', function () {
    Bus::fake();
    config(['notifications.fanout.chunk_size' => 2]);
    [$instructor, $course] = fanoutInstructorCourse(5);
    Sanctum::actingAs($instructor);

    $this->postJson("/api/v1/teach/courses/{$course->public_id}/announcements", ['title' => 'T', 'body' => 'B'])
        ->assertCreated();

    // 5 recipients / chunk size 2 → 3 chunk jobs in the batch.
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 3);
});

it('delivers to every recipient exactly once and is safe to re-run (idempotent)', function () {
    $userIds = [
        User::factory()->create()->id,
        User::factory()->create()->id,
        User::factory()->create()->id,
    ];
    $data = ['title' => 'T', 'body' => 'B', 'course' => 'C'];

    $run = fn () => (new FanOutNotificationJob($userIds, NotificationCategory::Learning, 'course_announcement', $data))
        ->handle(app(BulkNotificationAction::class));

    $run();
    expect(Notification::where('type', 'course_announcement')->count())->toBe(3);

    // A retry of the same chunk must not create duplicates (Sprint 3 deterministic dedup).
    $run();
    expect(Notification::where('type', 'course_announcement')->count())->toBe(3);
});
