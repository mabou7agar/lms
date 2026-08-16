<?php

namespace App\Contexts\Learning\Adapters;

use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Events\UserEnrolled;
use App\Contexts\Learning\Models\Enrollment;
use App\Platform\Shared\Learning\Contracts\CompanySeatEnrollmentPort;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Learning's implementation of CompanySeatEnrollmentPort.
 *
 * The whole point of this adapter is the ownership rule: an enrollment the learner obtained some
 * other way — bought it themselves, was given it manually, took it for free — is left completely
 * alone. Only a row whose source is CompanySeat is created, dated, or withdrawn here. That is what
 * stops a company's purchase window from expiring a learner's own access, and stops a manager
 * revoking a seat from deleting a course somebody paid for out of their own pocket.
 */
final class CompanySeatEnrollmentAdapter implements CompanySeatEnrollmentPort
{
    public function grantCompanySeat(int $courseId, int $userId, ?string $accessEndsAt): void
    {
        $expiresAt = $accessEndsAt === null ? null : Carbon::parse($accessEndsAt);

        $created = DB::transaction(function () use ($courseId, $userId, $expiresAt): ?Enrollment {
            $enrollment = Enrollment::where('user_id', $userId)
                ->where('course_id', $courseId)
                ->lockForUpdate()
                ->first();

            if ($enrollment === null) {
                return Enrollment::create([
                    'user_id' => $userId,
                    'course_id' => $courseId,
                    'status' => EnrollmentStatus::Active->value,
                    'source' => EnrollmentSource::CompanySeat->value,
                    'enrolled_at' => now(),
                    'expires_at' => $expiresAt,
                ]);
            }

            // Access from any other source outranks a company seat and is never rewritten: the
            // learner already owns this course on their own terms.
            if ($enrollment->source !== EnrollmentSource::CompanySeat) {
                return null;
            }

            // Re-granting refreshes the window (a renewed purchase extends access) and revives a
            // seat that had been revoked.
            $enrollment->forceFill([
                'status' => $enrollment->statusEnum() === EnrollmentStatus::Cancelled
                    ? EnrollmentStatus::Active->value
                    : $enrollment->statusEnum()->value,
                'enrolled_at' => $enrollment->enrolled_at ?? now(),
                'expires_at' => $expiresAt,
            ])->save();

            return null;
        });

        if ($created instanceof Enrollment) {
            UserEnrolled::dispatch($created);
        }
    }

    public function revokeCompanySeat(int $courseId, int $userId): void
    {
        Enrollment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('source', EnrollmentSource::CompanySeat->value)
            ->update(['status' => EnrollmentStatus::Cancelled->value]);
    }

    public function hasStartedCourse(int $courseId, int $userId): bool
    {
        return $this->courseProgressPercentage($courseId, $userId) > 0;
    }

    public function courseProgressPercentage(int $courseId, int $userId): int
    {
        return (int) Enrollment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->max('progress_percentage');
    }

    /**
     * @param  list<int>  $courseIds
     */
    public function highestProgressPercentage(array $courseIds, int $userId): int
    {
        if ($courseIds === []) {
            return 0;
        }

        return (int) Enrollment::where('user_id', $userId)
            ->whereIn('course_id', $courseIds)
            ->max('progress_percentage');
    }
}
