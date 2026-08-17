<?php

namespace App\Platform\Shared\Analytics;

/**
 * The event vocabulary. A closed set on purpose: a funnel built on free-text names drifts into
 * `checkout_start`, `checkoutStarted` and `checkout-started` within a month, and every report that
 * counted one of them silently under-reports for good.
 *
 * Lives in SHARED, not in the Analytics context, for the same reason AnalyticsAccess does: every
 * bounded context produces events, and a context may depend only on Shared. Without this the
 * producers would either import an Analytics enum (a Deptrac violation) or repeat string literals
 * that drift apart the first time one is renamed.
 *
 * SERVER events are authoritative — they are recorded where the thing actually happened, inside the
 * same request that changed the database. CLIENT events describe intent and attention, which only
 * the browser can see; they are accepted from the client and are therefore never used where money
 * is being counted.
 */
enum AnalyticsEventName: string
{
    // ── Server-side, authoritative ────────────────────────────────────────────────────────────
    case OrderPlaced = 'order_placed';
    case OrderPaid = 'order_paid';
    case CheckoutFailed = 'checkout_failed';
    case RefundIssued = 'refund_issued';
    case CartItemAdded = 'cart_item_added';
    case CompanySeatAssigned = 'company_seat_assigned';
    case CompanySeatRevoked = 'company_seat_revoked';
    case CertificateIssued = 'certificate_issued';
    case CourseCompleted = 'course_completed';
    case LessonCompleted = 'lesson_completed';
    case FileDownloaded = 'file_downloaded';
    case QnaQuestionAsked = 'qna_question_asked';
    case QnaAnswerPosted = 'qna_answer_posted';
    case QnaFirstResponse = 'qna_first_response';
    case QnaQuestionAccepted = 'qna_question_accepted';
    case AccessExpiringReminderSent = 'access_expiring_reminder_sent';
    case CertificateExpiringReminderSent = 'certificate_expiring_reminder_sent';

    // ── Client-side, reported by the browser ──────────────────────────────────────────────────
    case CourseViewed = 'course_viewed';
    case BundleViewed = 'bundle_viewed';
    case AddToCartClicked = 'add_to_cart_clicked';
    case CheckoutStarted = 'checkout_started';
    case SearchPerformed = 'search_performed';
    case CtaClicked = 'cta_clicked';

    /**
     * Events the collector will accept from a browser. Everything else is server-only: a client
     * that could post `order_paid` could invent revenue.
     *
     * @return list<string>
     */
    public static function clientReportable(): array
    {
        return array_map(static fn (self $e): string => $e->value, [
            self::CourseViewed,
            self::BundleViewed,
            self::AddToCartClicked,
            self::CheckoutStarted,
            self::SearchPerformed,
            self::CtaClicked,
        ]);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $e): string => $e->value, self::cases());
    }
}
