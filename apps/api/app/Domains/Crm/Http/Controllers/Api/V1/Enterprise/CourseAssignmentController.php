<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1\Enterprise;

use App\Domains\Crm\Http\Requests\Enterprise\CourseAssignmentRequest;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Services\OrganizationTargetResolver;
use App\Platform\Shared\Catalog\Contracts\CourseLookupPort;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use App\Platform\Shared\Learning\Contracts\EnrollmentGrantPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Enterprise course assignment: owner/admin grants an existing published course to an org scope.
 * Grants through the Shared EnrollmentGrantPort (implemented by Learning) so entitlement semantics
 * stay centralized and idempotent, and CRM never imports a Learning class.
 *
 * This is the FREE grant: it hands out a catalog course with no seat accounting behind it, and is
 * unrelated to what the organization has paid for. Seats bought from a company purchase are handed
 * out by CompanyEntitlementController instead, which enforces capacity, expiry and reassignment
 * policy. Both resolve their target the same way, through OrganizationTargetResolver.
 */
class CourseAssignmentController extends EnterpriseController
{
    public function __construct(
        private readonly CourseLookupPort $courses,
        private readonly CourseEnrollmentPort $enrollments,
        private readonly EnrollmentGrantPort $grants,
        private readonly OrganizationTargetResolver $targets,
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

        $members = $this->targets->resolve(
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
}
