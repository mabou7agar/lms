<?php

namespace App\Contexts\Learning\Actions\Enrollment;

use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Events\UserEnrolled;
use App\Contexts\Learning\Models\Enrollment;
use App\Platform\Shared\Actions\BaseAction;
use Carbon\CarbonInterface;

/**
 * Grants an enrollment idempotently. This is the entitlement seam other domains call (Commerce
 * calls it on a paid order). Re-granting an active enrollment is a no-op. Callers pass ids
 * (user id + course id) — no Identity/Catalog model dependency.
 *
 * `$expiresAt` carries the ACCESS WINDOW the grant was sold with — a product sold as "12 months"
 * has to stop after twelve months, and before this existed an individual purchase was silently
 * granted forever regardless of what the admin configured. Null means lifetime, which is both the
 * default and what every free, manual and legacy grant keeps.
 *
 * On a RE-grant the window is refreshed only when the existing row came from the same source: a
 * renewal should extend access, but a purchase must never quietly re-date an enrollment somebody
 * obtained another way.
 */
class GrantEnrollmentAction extends BaseAction
{
    public function executeByUserId(
        int $userId,
        int $courseId,
        EnrollmentSource $source = EnrollmentSource::Grant,
        ?CarbonInterface $expiresAt = null,
    ): Enrollment {
        [$enrollment, $created] = $this->transaction(function () use ($userId, $courseId, $source, $expiresAt): array {
            $enrollment = Enrollment::where('user_id', $userId)
                ->where('course_id', $courseId)
                ->lockForUpdate()
                ->first();

            if ($enrollment !== null) {
                $changes = [];

                // Reactivate a cancelled enrollment; otherwise leave as-is (idempotent).
                if ($enrollment->status === EnrollmentStatus::Cancelled) {
                    $changes['status'] = EnrollmentStatus::Active->value;
                    $changes['enrolled_at'] = $enrollment->enrolled_at ?? now();
                }

                // A repeat purchase of the same product renews it; a grant from elsewhere is left
                // exactly as it is, window and all.
                if ($expiresAt !== null && $enrollment->source === $source) {
                    $changes['expires_at'] = $expiresAt;
                }

                if ($changes !== []) {
                    $enrollment->forceFill($changes)->save();
                }

                return [$enrollment, false];
            }

            $enrollment = Enrollment::create([
                'user_id' => $userId,
                'course_id' => $courseId,
                'status' => EnrollmentStatus::Active->value,
                'source' => $source->value,
                'enrolled_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            return [$enrollment, true];
        });

        if ($created) {
            UserEnrolled::dispatch($enrollment);
        }

        return $enrollment;
    }
}
