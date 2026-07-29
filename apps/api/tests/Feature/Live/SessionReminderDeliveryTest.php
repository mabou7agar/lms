<?php

use App\Domains\Live\Enums\ReminderStatus;
use App\Domains\Live\Models\LiveSession;
use App\Domains\Live\Models\SessionRegistration;
use App\Domains\Live\Models\SessionReminder;
use App\Platform\Identity\Models\User;
use App\Platform\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * H9 — the reminder consumer. A due, pending reminder is delivered to the session's registered
 * participants exactly once, marked Sent, and never re-delivered. Runs the full chain synchronously
 * (sync queue in tests): command → job → SessionReminderDue → Notifications consumer → dispatcher.
 */
function dueReminderWithRegistrants(int $registrants): SessionReminder
{
    $session = LiveSession::factory()->create(['starts_at' => now()->addHour()]);

    for ($i = 0; $i < $registrants; $i++) {
        SessionRegistration::create([
            'session_id' => $session->id, 'user_id' => User::factory()->create()->id,
            'status' => 'registered', 'registered_at' => now(),
        ]);
    }

    return SessionReminder::create([
        'session_id' => $session->id, 'offset_minutes' => 60, 'channel' => 'email',
        'scheduled_at' => now()->subMinute(), 'status' => ReminderStatus::Pending->value,
    ]);
}

it('delivers a due reminder to every registered participant and marks it sent (H9)', function () {
    $reminder = dueReminderWithRegistrants(2);

    $this->artisan('live:dispatch-reminders')->assertExitCode(0);

    expect($reminder->fresh()->status)->toBe(ReminderStatus::Sent)
        ->and(Notification::where('type', 'session_reminder')->count())->toBe(2);
});

it('does not re-deliver an already-sent reminder (idempotent)', function () {
    dueReminderWithRegistrants(2);

    $this->artisan('live:dispatch-reminders'); // delivers → Sent
    $this->artisan('live:dispatch-reminders'); // nothing pending → no-op

    // Still exactly one notification per participant — no duplicates across runs.
    expect(Notification::where('type', 'session_reminder')->count())->toBe(2);
});

it('ignores reminders that are not yet due', function () {
    $session = LiveSession::factory()->create(['starts_at' => now()->addDay()]);
    SessionReminder::create([
        'session_id' => $session->id, 'offset_minutes' => 60, 'channel' => 'email',
        'scheduled_at' => now()->addHour(), 'status' => ReminderStatus::Pending->value,
    ]);

    $this->artisan('live:dispatch-reminders')->assertExitCode(0);

    expect(Notification::where('type', 'session_reminder')->count())->toBe(0)
        ->and(SessionReminder::where('status', ReminderStatus::Pending->value)->count())->toBe(1);
});
