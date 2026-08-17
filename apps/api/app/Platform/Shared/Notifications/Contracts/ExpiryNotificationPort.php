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

    /**
     * A learner's question has gone unanswered past the response promise the platform makes on the
     * course team's behalf. Sent to that team.
     *
     * It belongs on this port rather than a new one because it is the same kind of event: something
     * with a clock on it has run out. Dedup is per (question, recipient) — a question breaches its
     * SLA once, and a sweep that keeps finding it must not keep saying so.
     *
     * Returns whether this call is what created the notice. The other methods here are fire and
     * forget, but this one is driven by a command an operator runs by hand and reads the count of,
     * and a sweep that reports "3 sent" on its fifth run over the same three questions is telling
     * that operator something untrue.
     *
     * @param  string  $questionRef  the question's public id — the dedup anchor.
     * @return bool true when a new notice was created, false when this question was already reported.
     */
    public function qnaQuestionOverdue(
        int $recipientUserId,
        string $questionRef,
        string $questionTitle,
        string $courseTitle,
        int $hoursWaiting,
    ): bool;
}
