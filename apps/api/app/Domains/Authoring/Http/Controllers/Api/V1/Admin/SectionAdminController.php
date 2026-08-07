<?php

namespace App\Domains\Authoring\Http\Controllers\Api\V1\Admin;

use App\Domains\Authoring\Actions\Section\CreateSectionAction;
use App\Domains\Authoring\Actions\Section\DeleteSectionAction;
use App\Domains\Authoring\Actions\Section\DuplicateSectionAction;
use App\Domains\Authoring\Actions\Section\ReorderSectionsAction;
use App\Domains\Authoring\Actions\Section\SetSectionPublishStateAction;
use App\Domains\Authoring\Actions\Section\UpdateSectionAction;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Http\Requests\CreateSectionRequest;
use App\Domains\Authoring\Http\Requests\ReorderRequest;
use App\Domains\Authoring\Http\Requests\SetPublishStateRequest;
use App\Domains\Authoring\Http\Requests\UpdateSectionRequest;
use App\Domains\Authoring\Http\Resources\SectionResource;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class SectionAdminController extends Controller
{
    public function store(CreateSectionRequest $request, Course $course, CreateSectionAction $action): JsonResponse
    {
        Gate::authorize('authoring.manage-curriculum', $course);

        return ApiResponse::created(new SectionResource($action->execute($course, $request->validated())));
    }

    /**
     * Deep-copy a section and all of its lessons into the same course. {section} is bound
     * independently of {course}: the caller must manage the course (Gate), and the section must
     * actually belong to it — a foreign section id 404s rather than being copied across courses.
     */
    public function duplicate(Course $course, Section $section, DuplicateSectionAction $action): JsonResponse
    {
        Gate::authorize('authoring.manage-curriculum', $course);

        abort_unless((int) $section->course_id === (int) $course->id, 404);

        return ApiResponse::created(new SectionResource($action->execute($section)));
    }

    public function update(UpdateSectionRequest $request, Section $section, UpdateSectionAction $action): JsonResponse
    {
        Gate::authorize('update', $section);

        return ApiResponse::updated(new SectionResource(
            $action->execute($section, $request->validated(), $request->expectedVersion()),
        ));
    }

    public function destroy(Section $section, DeleteSectionAction $action): JsonResponse
    {
        Gate::authorize('delete', $section);
        $action->execute($section);

        return ApiResponse::deleted('Section deleted.');
    }

    public function reorder(ReorderRequest $request, Course $course, ReorderSectionsAction $action): JsonResponse
    {
        Gate::authorize('authoring.manage-curriculum', $course);
        $action->execute($course, $request->validated()['order']);

        return ApiResponse::success(null, 'Sections reordered.');
    }

    public function publish(SetPublishStateRequest $request, Section $section, SetSectionPublishStateAction $action): JsonResponse
    {
        Gate::authorize('update', $section);
        $state = PublishState::from($request->validated()['state']);

        return ApiResponse::updated(new SectionResource($action->execute($section, $state)));
    }
}
