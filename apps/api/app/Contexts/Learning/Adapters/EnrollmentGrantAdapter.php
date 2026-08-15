<?php

namespace App\Contexts\Learning\Adapters;

use App\Contexts\Learning\Actions\Enrollment\GrantEnrollmentAction;
use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Platform\Shared\Learning\Contracts\EnrollmentGrantPort;

/**
 * Learning's implementation of EnrollmentGrantPort. Delegates to GrantEnrollmentAction with the Grant
 * source so all entitlement semantics (idempotency, status/seat handling) stay centralized in
 * Learning — other contexts (CRM enterprise portal) grant courses without importing a Learning class.
 */
final class EnrollmentGrantAdapter implements EnrollmentGrantPort
{
    public function __construct(private readonly GrantEnrollmentAction $grant) {}

    public function grant(int $courseId, int $userId): void
    {
        $this->grant->executeByUserId($userId, $courseId, EnrollmentSource::Grant);
    }
}
