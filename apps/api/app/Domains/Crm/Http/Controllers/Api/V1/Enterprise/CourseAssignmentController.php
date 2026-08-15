<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1\Enterprise;

use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Http\Requests\Enterprise\CourseAssignmentRequest;
use App\Domains\Crm\Models\Department;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\Team;
use App\Platform\Shared\Catalog\Contracts\CourseLookupPort;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use App\Platform\Shared\Learning\Contracts\EnrollmentGrantPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Enterprise course assignment: owner/admin grants an existing published course to an org scope.
 * Grants through the Shared EnrollmentGrantPort (implemented by Learning) so entitlement semantics
 * stay centralized and idempotent, and CRM never imports a Learning class.
 */
class CourseAssignmentController extends EnterpriseController
{
    public function __construct(
        private readonly CourseLookupPort $courses,
        private readonly CourseEnrollmentPort $enrollments,
        private readonly EnrollmentGrantPort $grants,
    ) {}

    public function store(CourseAssignmentRequest $request): JsonResponse
    {
        Gate::authorize('manageMembers', OrganizationMember::class);

        $organization = $this->organization($request);
        $data = $request->validated();
        $course = $this->courses->publishedCourseByPublicId((string) $data['course_id']);

        if ($course === null) {
            throw new NotFoundHttpException('Course not found.');
        }

        $members = $this->targetMembers(
            (string) $data['target_type'],
            isset($data['target_id']) ? (string) $data['target_id'] : null,
            (int) $organization->getKey(),
        );

        $eligibleUserIds = $members
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $created = 0;
        $alreadyAssigned = 0;

        foreach ($eligibleUserIds as $userId) {
            $before = $this->enrollments->hasCourseAccess((int) $course['id'], $userId);
            if ($before) {
                $alreadyAssigned++;
            }

            $this->grants->grant((int) $course['id'], $userId);
            if (! $before) {
                $created++;
            }
        }

        return ApiResponse::success([
            'course' => [
                'id' => $course['public_id'],
                'title' => $course['title'],
            ],
            'target' => [
                'type' => (string) $data['target_type'],
                'id' => $data['target_id'] ?? null,
            ],
            'summary' => [
                'matched_members' => $members->count(),
                'eligible_members' => $eligibleUserIds->count(),
                'assigned' => $created,
                'already_assigned' => $alreadyAssigned,
                'skipped_without_account' => $members->whereNull('user_id')->count(),
            ],
        ], 'Course assigned.');
    }

    /** @return EloquentCollection<int, OrganizationMember> */
    private function targetMembers(string $targetType, ?string $targetId, int $organizationId): EloquentCollection
    {
        $base = OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('status', MemberStatus::Active->value);

        return match ($targetType) {
            'organization' => $base->get(),
            'member' => $this->singleMember($base, $this->requiredTargetId($targetId)),
            'department' => $this->departmentMembers($base, $this->requiredTargetId($targetId), $organizationId),
            'team' => $this->teamMembers($base, $this->requiredTargetId($targetId), $organizationId),
            default => new EloquentCollection(),
        };
    }

    /**
     * @param  Builder<OrganizationMember>  $base
     * @return EloquentCollection<int, OrganizationMember>
     */
    private function singleMember(Builder $base, string $memberId): EloquentCollection
    {
        $members = $base->where('public_id', $memberId)->get();

        if ($members->isEmpty()) {
            throw new NotFoundHttpException('Member not found.');
        }

        return $members;
    }

    /**
     * @param  Builder<OrganizationMember>  $base
     * @return EloquentCollection<int, OrganizationMember>
     */
    private function departmentMembers(Builder $base, string $departmentId, int $organizationId): EloquentCollection
    {
        $department = Department::query()
            ->where('organization_id', $organizationId)
            ->where('public_id', $departmentId)
            ->first();

        if ($department === null) {
            throw new NotFoundHttpException('Department not found.');
        }

        return $base->where('department_id', $department->getKey())->get();
    }

    /**
     * @param  Builder<OrganizationMember>  $base
     * @return EloquentCollection<int, OrganizationMember>
     */
    private function teamMembers(Builder $base, string $teamId, int $organizationId): EloquentCollection
    {
        $team = Team::query()
            ->where('organization_id', $organizationId)
            ->where('public_id', $teamId)
            ->first();

        if ($team === null) {
            throw new NotFoundHttpException('Team not found.');
        }

        return $base
            ->whereHas('teams', fn (Builder $query) => $query->where('crm_teams.id', $team->getKey()))
            ->get();
    }

    private function requiredTargetId(?string $targetId): string
    {
        if ($targetId === null || $targetId === '') {
            throw new NotFoundHttpException('Target not found.');
        }

        return $targetId;
    }
}
