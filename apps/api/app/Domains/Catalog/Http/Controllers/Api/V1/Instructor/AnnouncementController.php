<?php

namespace App\Domains\Catalog\Http\Controllers\Api\V1\Instructor;

use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Http\Resources\Instructor\CourseAnnouncementResource;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseAnnouncement;
use App\Platform\Notifications\Actions\BulkNotificationAction;
use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends InstructorController
{
    /** GET /teach/courses/{course}/announcements — the course's announcements (newest first). */
    public function index(Request $request, Course $course): JsonResponse
    {
        $course = $this->ownedCourse($request, $course);

        $announcements = CourseAnnouncement::query()
            ->where('course_id', $course->id)
            ->latest('published_at')
            ->latest('id')
            ->get();

        return ApiResponse::success(CourseAnnouncementResource::collection($announcements));
    }

    /** POST /teach/courses/{course}/announcements — create + fan out to enrolled learners. */
    public function store(Request $request, Course $course, BulkNotificationAction $notifications): JsonResponse
    {
        $course = $this->ownedCourse($request, $course);
        $instructor = $this->instructor($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $announcement = CourseAnnouncement::create([
            'course_id' => $course->id,
            'author_id' => $instructor->actorId(),
            'title' => $validated['title'],
            'body' => $validated['body'],
            'published_at' => now(),
        ]);

        $studentIds = Enrollment::query()
            ->where('course_id', $course->id)
            ->pluck('user_id')
            ->map(static fn ($v): int => (int) $v)
            ->unique()
            ->values()
            ->all();

        // H4 — async, chunked fan-out. The request returns immediately after enqueuing one Bus batch
        // of chunk jobs; workers do the delivery. A 10k-learner course no longer times out the POST.
        // Behaviour is unchanged: the same dispatcher, so each learner still receives the announcement
        // exactly once (Sprint 3 de-duplication), just off the request thread.
        $notifications->queueForUserIds(
            $studentIds,
            NotificationCategory::Learning,
            'course_announcement',
            ['title' => $announcement->title, 'body' => $announcement->body, 'course' => $course->title],
            'course-announcement:'.$announcement->getKey(),
        );

        return ApiResponse::created(new CourseAnnouncementResource($announcement));
    }
}
