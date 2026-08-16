<?php

namespace App\Platform\Shared\Certification\Data;

/**
 * A credential approaching the end of its validity, as the reminder sweep needs to see it: who holds
 * it, what it is for, and when it lapses. Deliberately thin — no PDF path, no verification code, no
 * signature. A reminder needs a name and a date, not the credential itself.
 */
final readonly class ExpiringCertificate
{
    public function __construct(
        public string $publicId,
        public string $number,
        public int $userId,
        public int $courseId,
        public string $courseTitle,
        public string $expiresAt,
    ) {}
}
