<?php

namespace App\Platform\Notifications\Services;

use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Shared\Notifications\Contracts\ExpiryNotificationPort;

/**
 * The expiry-reminder implementation of {@see ExpiryNotificationPort}, following the same shape as
 * {@see LearningNotificationService}: map each intent to a category + template key + deterministic
 * dedup key, then let {@see NotificationDispatcher} handle locale, preferences, channel availability
 * and queued delivery.
 *
 * The dedup key is what makes the scheduled sweep safe to run as often as you like. It is keyed by
 * (kind, reference, recipient, daysBefore), so the "30 days left" notice for one purchase exists
 * exactly once no matter how many times the sweep sees that row, while the "7 days left" notice is a
 * genuinely different event and still gets through. No separate reminder ledger is needed — the
 * notifications table, with its unique index on dedup_key, IS the ledger.
 *
 * Purchases and credentials are classified under Commerce and Certification respectively, so a
 * learner who mutes one category still hears about the other.
 */
class ExpiryNotificationService implements ExpiryNotificationPort
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function companyPurchaseExpiring(
        int $recipientUserId,
        string $entitlementRef,
        string $productTitle,
        string $expiresAt,
        int $daysBefore,
        int $seatsAffected,
    ): void {
        $this->dispatcher->dispatchToUserId(
            $recipientUserId,
            NotificationCategory::Commerce,
            'company_purchase_expiring',
            [
                'title' => $productTitle,
                'days' => $daysBefore,
                'expires_at' => $this->day($expiresAt),
                'seats' => $seatsAffected,
            ],
            null,
            'company-purchase-expiring:'.$entitlementRef.':'.$recipientUserId.':'.$daysBefore,
        );
    }

    public function seatAccessExpiring(
        int $recipientUserId,
        string $entitlementRef,
        string $productTitle,
        string $expiresAt,
        int $daysBefore,
    ): void {
        $this->dispatcher->dispatchToUserId(
            $recipientUserId,
            NotificationCategory::Commerce,
            'seat_access_expiring',
            [
                'title' => $productTitle,
                'days' => $daysBefore,
                'expires_at' => $this->day($expiresAt),
            ],
            null,
            'seat-access-expiring:'.$entitlementRef.':'.$recipientUserId.':'.$daysBefore,
        );
    }

    public function certificateExpiring(
        int $recipientUserId,
        string $certificateRef,
        string $certificateNumber,
        string $courseTitle,
        string $expiresAt,
        int $daysBefore,
    ): void {
        $this->dispatcher->dispatchToUserId(
            $recipientUserId,
            NotificationCategory::Certification,
            'certificate_expiring',
            [
                'title' => $courseTitle,
                'number' => $certificateNumber,
                'days' => $daysBefore,
                'expires_at' => $this->day($expiresAt),
            ],
            null,
            'certificate-expiring:'.$certificateRef.':'.$recipientUserId.':'.$daysBefore,
        );
    }

    public function qnaQuestionOverdue(
        int $recipientUserId,
        string $questionRef,
        string $questionTitle,
        string $courseTitle,
        int $hoursWaiting,
    ): bool {
        return $this->dispatcher->dispatchToUserId(
            $recipientUserId,
            NotificationCategory::Learning,
            'qna_question_overdue',
            [
                'title' => $questionTitle,
                'course' => $courseTitle,
                'hours' => $hoursWaiting,
            ],
            null,
            // No offset in the key: a question breaches its promise once, and the sweep that keeps
            // finding it every night must not keep saying so.
            'qna-overdue:'.$questionRef.':'.$recipientUserId,
        )->wasRecentlyCreated;
    }

    /** Dates in a reminder are read by a person, so the time of day is noise. */
    private function day(string $iso): string
    {
        return substr($iso, 0, 10);
    }
}
