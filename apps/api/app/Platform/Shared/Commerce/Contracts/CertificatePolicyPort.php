<?php

namespace App\Platform\Shared\Commerce\Contracts;

use App\Platform\Shared\Commerce\Data\CertificateCommercialPolicy;

/**
 * "This learner just finished this course — what certificate did they actually buy?"
 *
 * DECLARED in Shared, IMPLEMENTED by Commerce (which owns products, orders and seat pools),
 * CONSUMED by Certification at the moment of issue. Certification asks once and stores the answer;
 * it never learns what a product or an entitlement is.
 *
 * A course nobody sells resolves to {@see CertificateCommercialPolicy::unrestricted()} — a
 * certificate, platform-branded, no expiry — so free and legacy courses keep behaving exactly as
 * they did before certificates had a commercial policy at all.
 */
interface CertificatePolicyPort
{
    /**
     * @param  string  $issuedAt  ISO-8601 instant the certificate is being issued, so any relative
     *                            expiry ("two years from issue") is resolved against the real date.
     */
    public function certificatePolicyFor(int $userId, int $courseId, string $issuedAt): CertificateCommercialPolicy;
}
