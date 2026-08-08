<?php

use App\Contexts\Commerce\Models\PaymentWebhookEvent;
use Illuminate\Support\Facades\Schedule;

// Horizon queue metrics snapshots (required for the Horizon dashboard graphs). One server only —
// duplicate snapshots across a multi-node deploy would corrupt the metric series.
Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer()->withoutOverlapping();

// Prune expired Sanctum tokens, old failed jobs, and stale password-reset tokens.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
// Dead-letter retention (Sprint 5): keep failed_jobs long enough to actually investigate an
// incident. The prior 7-day window destroyed failure evidence before most post-mortems finished;
// default is now 30 days (720h), operator-tunable via QUEUE_FAILED_PRUNE_HOURS.
Schedule::command('queue:prune-failed --hours='.(int) config('queue.failed.prune_hours', 720))
    ->daily()->onOneServer()->withoutOverlapping();
Schedule::command('auth:clear-resets')->daily();

// H9 — deliver due session reminders. Enqueues delivery jobs for reminders whose time has come.
// One server + no overlap so a due reminder is enqueued once per minute-tick; the job's own
// pending-status guard + per-recipient dedup key make delivery idempotent regardless.
Schedule::command('live:dispatch-reminders')->everyMinute()->onOneServer()->withoutOverlapping();

// Auto-publish courses whose scheduled publish time has arrived and that pass readiness. One
// server + no overlap so each due course is considered once per minute-tick; the state machine's
// readiness guard makes a premature or repeated run harmless (an unready course stays Scheduled).
Schedule::command('courses:publish-scheduled')->everyMinute()->onOneServer()->withoutOverlapping();

// Processed payment webhook events are kept 30 days for reconciliation, then pruned.
Schedule::call(function (): void {
    PaymentWebhookEvent::query()
        ->whereNotNull('processed_at')
        ->where('processed_at', '<', now()->subDays(30))
        ->delete();
})->daily()->name('commerce-prune-processed-webhook-events')->onOneServer()->withoutOverlapping();
