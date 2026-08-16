<?php

namespace App\Platform\Shared\Commerce\Data;

/**
 * What the commercial side says about a certificate a learner is about to earn: whether one is
 * included at all, when it stops being valid, and whose marks it carries.
 *
 * Certification knows how to mint and verify a credential; it has no business knowing about
 * products, orders or seat pools. This DTO is the whole of what crosses that seam — resolved dates
 * and strings, never a Commerce model and never an enum Certification would have to import.
 *
 * `expiresAt` is already resolved against the issue instant by the side that owns the policy, so
 * Certification stores a date rather than re-deriving one from rules it should not know.
 */
final readonly class CertificateCommercialPolicy
{
    /**
     * @param  bool  $enabled  false means no certificate is issued at all for this course.
     * @param  string|null  $expiresAt  ISO-8601 instant, or null when the credential never lapses.
     * @param  string|null  $brandingMode  one of the company branding modes, or null for platform branding.
     * @param  int|null  $organizationId  the company whose purchase earned this, when it was a company seat.
     * @param  string|null  $companyName  snapshot of that company's name at issue.
     * @param  string|null  $companyLogoUrl  snapshot of its logo, when the branding mode shows one.
     */
    public function __construct(
        public bool $enabled,
        public ?string $expiresAt = null,
        public ?string $brandingMode = null,
        public ?int $organizationId = null,
        public ?string $companyName = null,
        public ?string $companyLogoUrl = null,
    ) {}

    /** The default when no product governs the course: a certificate, platform-branded, forever. */
    public static function unrestricted(): self
    {
        return new self(enabled: true);
    }

    /** No certificate is included with this course. */
    public static function disabled(): self
    {
        return new self(enabled: false);
    }
}
