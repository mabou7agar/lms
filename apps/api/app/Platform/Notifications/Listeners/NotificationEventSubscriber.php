<?php

namespace App\Platform\Notifications\Listeners;

use App\Contexts\Commerce\Events\OrderPaid;
use App\Contexts\Learning\Events\CourseCompleted;
use App\Contexts\Learning\Events\UserEnrolled;
use App\Domains\Certification\Events\CertificateIssued;
use App\Domains\Crm\Events\ConsultingRequestCreated;
use App\Domains\Live\Events\SessionReminderDue;
use App\Domains\Live\Events\SessionScheduled;
use App\Platform\Identity\Events\UserRegistered;
use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Notifications\Services\NotificationDispatcher;
use Illuminate\Events\Dispatcher;

/**
 * The notifications consumer. Reacts to producer EVENTS ONLY (reading the user off each event's
 * aggregate) and dispatches queued notifications. It never imports producer models/tables.
 */
class NotificationEventSubscriber
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function onUserRegistered(UserRegistered $event): void
    {
        $this->dispatcher->dispatchToUserId($event->user->id, NotificationCategory::Account, 'welcome', ['name' => $event->user->name]);
    }

    public function onUserEnrolled(UserEnrolled $event): void
    {
        if ($event->enrollment->user_id !== null) {
            $this->dispatcher->dispatchToUserId($event->enrollment->user_id, NotificationCategory::Learning, 'enrollment_confirmed', []);
        }
    }

    public function onCourseCompleted(CourseCompleted $event): void
    {
        if ($event->enrollment->user_id !== null) {
            $this->dispatcher->dispatchToUserId($event->enrollment->user_id, NotificationCategory::Learning, 'course_completed', []);
        }
    }

    public function onOrderPaid(OrderPaid $event): void
    {
        if ($event->order->user_id !== null) {
            $this->dispatcher->dispatchToUserId($event->order->user_id, NotificationCategory::Commerce, 'order_receipt', ['total' => $event->order->total_minor]);
        }
    }

    public function onCertificateIssued(CertificateIssued $event): void
    {
        // certificates.user_id is a non-nullable FK, so the holder id is always present.
        $this->dispatcher->dispatchToUserId($event->certificate->user_id, NotificationCategory::Certification, 'certificate_ready', ['number' => $event->certificate->number]);
    }

    public function onSessionScheduled(SessionScheduled $event): void
    {
        // Announce to registered participants.
        foreach ($event->session->registrations()->where('status', 'registered')->get() as $registration) {
            // session_registrations.user_id is a non-nullable FK.
            $this->dispatcher->dispatchToUserId($registration->user_id, NotificationCategory::Live, 'session_scheduled', ['title' => $event->session->title]);
        }
    }

    public function onConsultingRequestCreated(ConsultingRequestCreated $event): void
    {
        if ($event->request->requested_by !== null) {
            $this->dispatcher->dispatchToUserId($event->request->requested_by, NotificationCategory::Crm, 'consulting_ack', ['subject' => $event->request->subject]);
        }
    }

    /**
     * H9 — a due session reminder. Same participant fan-out as onSessionScheduled, but keyed on the
     * reminder id so each (reminder, learner) is delivered exactly once (the explicit dedup key makes
     * retries and concurrent runs safe, and keeps two reminders for the same session distinct).
     */
    public function onSessionReminderDue(SessionReminderDue $event): void
    {
        foreach ($event->session->registrations()->where('status', 'registered')->get() as $registration) {
            // session_registrations.user_id is a non-nullable FK.
            $this->dispatcher->dispatchToUserId(
                $registration->user_id,
                NotificationCategory::Live,
                'session_reminder',
                ['title' => $event->session->title],
                null,
                'session-reminder:'.$event->reminderId.':user:'.$registration->user_id,
            );
        }
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            UserRegistered::class => 'onUserRegistered',
            UserEnrolled::class => 'onUserEnrolled',
            CourseCompleted::class => 'onCourseCompleted',
            OrderPaid::class => 'onOrderPaid',
            CertificateIssued::class => 'onCertificateIssued',
            SessionScheduled::class => 'onSessionScheduled',
            SessionReminderDue::class => 'onSessionReminderDue',
            ConsultingRequestCreated::class => 'onConsultingRequestCreated',
        ];
    }
}
