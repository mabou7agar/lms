<?php

declare(strict_types=1);

namespace App\Platform\AI\Http\Controllers;

use App\Platform\AI\Copilot\CopilotMode;
use App\Platform\AI\Copilot\CopilotService;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/ai/copilot — the INSTRUCTOR AI COPILOT.
 *
 * An instructor asks for a suggestion (draft/improve lesson copy, summarize learner questions, suggest
 * next content) about a course they OWN. Access is scoped in two layers:
 *   1. the principal must hold a portal role (instructor/admin/super_admin) — 403 otherwise; and
 *   2. the course is resolved ONLY if the principal may manage it, via the Identity CourseAccessPort
 *      (the single definition of course ownership) — a course they do not own is indistinguishable
 *      from a missing one (404).
 * {@see CopilotService} then produces a suggestion grounded in that course. It is read-only: it never
 * writes to a learner/grade record and never grades.
 */
final class CopilotController extends AbstractAiController
{
    /** @var list<string> */
    private const PORTAL_ROLES = ['instructor', 'admin', 'super_admin'];

    public function assist(
        Request $request,
        CopilotService $copilot,
        CourseAccessPort $access,
    ): JsonResponse {
        $data = $request->validate([
            'course_id' => ['required', 'string'],
            'mode' => ['required', 'string', Rule::in(CopilotMode::values())],
            'brief' => ['nullable', 'string', 'max:4000'],
        ]);

        $actor = $this->actor($request);

        if (! $this->hasPortalRole($actor)) {
            return ApiResponse::error('INSTRUCTOR_REQUIRED', 'Instructor access required.', [], 403);
        }

        // Resolve to an internal id ONLY if the instructor owns the course; null = missing OR not yours.
        $courseId = $access->manageableCourseId($actor, (string) $data['course_id']);
        if ($courseId === null) {
            return ApiResponse::error('COURSE_NOT_FOUND', 'Course not found.', [], 404);
        }

        $mode = CopilotMode::from((string) $data['mode']);

        return $this->runGuarded(fn (): JsonResponse => ApiResponse::success(
            $copilot->assist(
                mode: $mode,
                brief: (string) ($data['brief'] ?? ''),
                courseId: $courseId,
                userId: $actor->actorId(),
            )->toArray(),
        ));
    }

    private function hasPortalRole(Actor $actor): bool
    {
        foreach (self::PORTAL_ROLES as $role) {
            if ($actor->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
