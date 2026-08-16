<?php

namespace App\Domains\Certification\Support;

use App\Domains\Certification\Enums\CertificateStatus;
use App\Domains\Certification\Models\Certificate;
use App\Platform\Shared\Certification\Contracts\CertificateStatusPort;
use App\Platform\Shared\Certification\Data\ExpiringCertificate;

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

    public function issuedCountForUsers(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return Certificate::query()
            ->whereIn('user_id', $userIds)
            ->where('status', CertificateStatus::Issued->value)
            ->count();
    }

    /**
     * @return list<ExpiringCertificate>
     */
    public function expiringWithin(int $days): array
    {
        return Certificate::query()
            ->expiringWithin($days)
            ->with('course')
            ->orderBy('expires_at')
            ->get()
            ->map(fn (Certificate $c): ExpiringCertificate => new ExpiringCertificate(
                publicId: (string) $c->public_id,
                number: (string) $c->number,
                userId: (int) $c->user_id,
                courseId: (int) $c->course_id,
                courseTitle: (string) ($c->course?->getAttribute('title') ?? ''),
                expiresAt: (string) $c->expires_at?->toIso8601String(),
            ))
            ->values()
            ->all();
    }
}
