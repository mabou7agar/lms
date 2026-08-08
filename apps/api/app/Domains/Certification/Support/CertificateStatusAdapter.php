<?php

namespace App\Domains\Certification\Support;

use App\Domains\Certification\Enums\CertificateStatus;
use App\Domains\Certification\Models\Certificate;
use App\Platform\Shared\Certification\Contracts\CertificateStatusPort;

/**
 * Certification's implementation of the cross-context certificate-status port. The only place
 * outside this domain's own surfaces that certificate rows are read, so the coupling is one
 * auditable file.
 *
 * "Has a certificate" / "issued count" both mean a VALID certificate — status = Issued — never a
 * revoked one: a revoked certificate must not read as eligibility on an instructor's screen. Soft-
 * deleted rows are excluded by the model's SoftDeletes scope automatically.
 */
class CertificateStatusAdapter implements CertificateStatusPort
{
    public function hasCertificate(int $courseId, int $userId): bool
    {
        return Certificate::query()
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->where('status', CertificateStatus::Issued->value)
            ->exists();
    }

    public function issuedCountForCourse(int $courseId): int
    {
        return Certificate::query()
            ->where('course_id', $courseId)
            ->where('status', CertificateStatus::Issued->value)
            ->count();
    }
}
