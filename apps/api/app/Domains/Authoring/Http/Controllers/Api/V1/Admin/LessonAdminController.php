<?php

namespace App\Domains\Authoring\Http\Controllers\Api\V1\Admin;

use App\Domains\Authoring\Actions\Lesson\AttachAssessmentAction;
use App\Domains\Authoring\Actions\Lesson\CreateLessonAction;
use App\Domains\Authoring\Actions\Lesson\DeleteLessonAction;
use App\Domains\Authoring\Actions\Lesson\DuplicateLessonAction;
use App\Domains\Authoring\Actions\Lesson\ReorderLessonsAction;
use App\Domains\Authoring\Actions\Lesson\SetLessonPrerequisitesAction;
use App\Domains\Authoring\Actions\Lesson\SetLessonPublishStateAction;
use App\Domains\Authoring\Actions\Lesson\TogglePreviewAction;
use App\Domains\Authoring\Actions\Lesson\UpdateLessonAction;
use App\Domains\Authoring\Actions\Lesson\UpsertLessonMediaAction;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Http\Requests\AttachAssessmentRequest;
use App\Domains\Authoring\Http\Requests\CreateLessonRequest;
use App\Domains\Authoring\Http\Requests\ReorderRequest;
use App\Domains\Authoring\Http\Requests\SetPrerequisitesRequest;
use App\Domains\Authoring\Http\Requests\SetPublishStateRequest;
use App\Domains\Authoring\Http\Requests\UpdateLessonRequest;
use App\Domains\Authoring\Http\Requests\UpsertLessonMediaRequest;
use App\Domains\Authoring\Http\Resources\LessonMediaResource;
use App\Domains\Authoring\Http\Resources\LessonResource;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class LessonAdminController extends Controller
{
    public function store(CreateLessonRequest $request, Section $section, CreateLessonAction $action): JsonResponse
    {
        Gate::authorize('update', $section);

        return ApiResponse::created(new LessonResource($action->execute($section, $request->validated())));
    }

    /**
     * Deep-copy a lesson within its section. The lesson is bound independently of {section}, so its
     * ownership is verified two ways: the caller must manage the section (Gate), and the lesson must
     * actually belong to that section — a foreign lesson id (another course) resolves to a mismatch
     * and 404s rather than being copied into a course the caller does not own.
     */
    public function duplicate(Section $section, Lesson $lesson, DuplicateLessonAction $action): JsonResponse
    {
        Gate::authorize('update', $section);

        abort_unless((int) $lesson->section_id === (int) $section->id, 404);

        return ApiResponse::created(new LessonResource($action->execute($lesson)));
    }

    public function update(UpdateLessonRequest $request, Lesson $lesson, UpdateLessonAction $action): JsonResponse
    {
        Gate::authorize('update', $lesson);

        return ApiResponse::updated(new LessonResource($action->execute($lesson, $request->validated())));
    }

    public function destroy(Lesson $lesson, DeleteLessonAction $action): JsonResponse
    {
        Gate::authorize('delete', $lesson);
        $action->execute($lesson);

        return ApiResponse::deleted('Lesson deleted.');
    }

    public function reorder(ReorderRequest $request, Section $section, ReorderLessonsAction $action): JsonResponse
    {
        Gate::authorize('update', $section);
        $action->execute($section, $request->validated()['order']);

        return ApiResponse::success(null, 'Lessons reordered.');
    }

    public function publish(SetPublishStateRequest $request, Lesson $lesson, SetLessonPublishStateAction $action): JsonResponse
    {
        Gate::authorize('update', $lesson);
        $state = PublishState::from($request->validated()['state']);

        return ApiResponse::updated(new LessonResource($action->execute($lesson, $state)));
    }

    public function preview(Lesson $lesson, TogglePreviewAction $action): JsonResponse
    {
        Gate::authorize('update', $lesson);

        return ApiResponse::updated(new LessonResource($action->execute($lesson)));
    }

    public function prerequisites(SetPrerequisitesRequest $request, Lesson $lesson, SetLessonPrerequisitesAction $action): JsonResponse
    {
        Gate::authorize('update', $lesson);
        $lesson = $action->execute($lesson, $request->validated()['prerequisites']);

        return ApiResponse::updated(new LessonResource($lesson->load('prerequisites')));
    }

    /**
     * Point a quiz lesson at an assessment, or clear the reference with an explicit null.
     * Authorized like every other lesson mutation; the assessment itself is validated by the port.
     */
    public function assessment(AttachAssessmentRequest $request, Lesson $lesson, AttachAssessmentAction $action): JsonResponse
    {
        Gate::authorize('update', $lesson);

        $assessmentId = $request->validated()['assessment_id'] ?? null;

        return ApiResponse::updated(new LessonResource(
            $action->execute($lesson, is_string($assessmentId) ? $assessmentId : null),
        ));
    }

    public function media(UpsertLessonMediaRequest $request, Lesson $lesson, UpsertLessonMediaAction $action): JsonResponse
    {
        Gate::authorize('update', $lesson);

        return ApiResponse::updated(new LessonMediaResource($action->execute($lesson, $request->validated())));
    }
}
