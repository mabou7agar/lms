<?php

namespace App\Platform\Shared\Notifications\Contracts;

/**
 * "Something someone paid for is about to run out — tell them."
 *
 * DECLARED in Shared and IMPLEMENTED by the Notifications platform, exactly like
 * LearningNotificationPort: the producing side (Commerce, sweeping expiring purchases and
 * credentials) says only WHAT happened and to WHOM, and the implementation owns category, template,
 * channel selection and the dedup key.
 *
 * IDEMPOTENCY is the contract's most important promise, because these methods are called from a
 * scheduled sweep that runs repeatedly against the same rows. Each method dedups on
 * (kind, reference, recipient, daysBefore), so a sweep running hourly for a week sends one notice
 * per configured offset — not one per run. That is why `$daysBefore` is part of the signature rather
 * than a detail of the message: it is what makes "30 days out" and "7 days out" different events.
 *
 * Scalars only, no Eloquent, no throwing.
 */
interface ExpiryNotificationPort
{
    /**
     * A company's purchased training is approaching the end of its access window. Sent to the
     * people who run the organization, who are the only ones who can renew it.
     *
     * @param  string  $entitlementRef  the purchase's public id — the dedup anchor.
     */
    public function companyPurchaseExpiring(
        int $recipientUserId,
        string $entitlementRef,
        string $productTitle,
        string $expiresAt,
        int $daysBefore,
        int $seatsAffected,
    ): void;

    /**
     * An employee is about to lose course access their company bought. Sent to the employee, who
     * needs to know to finish or to ask their manager.
     */
    public function seatAccessExpiring(
        int $recipientUserId,
        string $entitlementRef,
        string $productTitle,
        string $expiresAt,
        int $daysBefore,
    ): void;

    /**
     * A credential the learner already earned is approaching the end of its validity.
     *
     * @param  string  $certificateRef  the certificate's public id — the dedup anchor.
     */
    public function certificateExpiring(
        int $recipientUserId,
        string $certificateRef,
        string $certificateNumber,
        string $courseTitle,
        string $expiresAt,
        int $daysBefore,
    ): void;
}
