<?php

namespace App\Platform\Shared\Certification\Contracts;

use App\Platform\Shared\Certification\Data\ExpiringCertificate;

/**
 * Certificate status for reporting surfaces outside the Certification context (the instructor
 * portal's learner drill-down and course analytics). DECLARED here in Shared, IMPLEMENTED by
 * Certification — which owns the certificates table — so Catalog never reads it directly.
 *
 * Deliberately narrow: it answers only "has this learner a valid certificate for this course" and
 * "how many valid certificates has this course issued". No PDF paths, verification codes, templates
 * or revocation detail cross this boundary. Scalar arguments and returns only, no Eloquent, no
 * throwing. Course ids are assumed already authorization-scoped by the caller.
 */
interface CertificateStatusPort
{
    /** True if the learner holds a VALID (issued, non-revoked) certificate for the course. */
    public function hasCertificate(int $courseId, int $userId): bool;

    /** Count of VALID certificates issued for the course. */
    public function issuedCountForCourse(int $courseId): int;

    /**
     * Count of VALID (issued, non-revoked) certificates held across the given learner user ids — a
     * bounded aggregate for the enterprise manager report. Empty ids => 0. The user ids MUST already
     * be authorization-scoped (an organization roster) by the caller.
     *
     * @param  list<int>  $userIds
     */
    public function issuedCountForUsers(array $userIds): int;

    /**
     * Valid credentials whose validity window closes inside the next `$days` days, oldest expiry
     * first. Drives the expiry-reminder sweep, which lives on the commercial side because that is
     * where the reminder cadence is configured — Certification supplies the facts, not the policy.
     *
     * Revoked, already-lapsed and never-expiring credentials are all excluded.
     *
     * @return list<ExpiringCertificate>
     */
    public function expiringWithin(int $days): array;
}
