<?php

namespace App\Domains\Live\Providers;

use App\Domains\Live\Calendar\CalendarProviderManager;
use App\Domains\Live\Console\Commands\DispatchSessionRemindersCommand;
use App\Domains\Live\Contracts\CalendarProvider;
use App\Domains\Live\Contracts\MeetingProvider;
use App\Domains\Live\Contracts\ReminderScheduler;
use App\Domains\Live\Events\SessionCancelled;
use App\Domains\Live\Events\SessionRescheduled;
use App\Domains\Live\Events\SessionScheduled;
use App\Domains\Live\Listeners\CancelRemindersOnSessionCancelled;
use App\Domains\Live\Listeners\RescheduleRemindersOnSessionRescheduled;
use App\Domains\Live\Listeners\ScheduleRemindersOnSessionScheduled;
use App\Domains\Live\Meeting\MeetingProviderManager;
use App\Domains\Live\Models\LiveSession;
use App\Domains\Live\Policies\LiveSessionPolicy;
use App\Domains\Live\Reminders\ReminderSchedulerManager;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
use Illuminate\Support\Facades\Event;

/**
 * Wires the Live module: config, migrations, routes, policy, the meeting/calendar/reminder
 * abstraction bindings (Fake/Null defaults — no vendor SDKs), and the reminder listeners.
 */
class LiveServiceProvider extends BaseDomainServiceProvider
{
    protected array $routeFiles = ['routes/live.php', 'routes/events_public.php'];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        LiveSession::class => LiveSessionPolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/live.php', 'live');

        $this->app->bind(MeetingProvider::class, fn ($app) => $app->make(MeetingProviderManager::class)->resolve());
        $this->app->bind(CalendarProvider::class, fn ($app) => $app->make(CalendarProviderManager::class)->resolve());
        $this->app->bind(ReminderScheduler::class, fn ($app) => $app->make(ReminderSchedulerManager::class)->resolve());

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchSessionRemindersCommand::class]);
        }
    }

    protected function bootDomain(): void
    {
        Event::listen(SessionScheduled::class, ScheduleRemindersOnSessionScheduled::class);
        Event::listen(SessionRescheduled::class, RescheduleRemindersOnSessionRescheduled::class);
        Event::listen(SessionCancelled::class, CancelRemindersOnSessionCancelled::class);
    }
}
