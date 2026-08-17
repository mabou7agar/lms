<?php

declare(strict_types=1);

namespace App\Domains\Qna\Providers;

use App\Domains\Qna\Console\Commands\SendOverdueQuestionRemindersCommand;
use App\Domains\Qna\Listeners\QnaNotificationSubscriber;
use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QuestionAnswer;
use App\Domains\Qna\Policies\CourseQuestionPolicy;
use App\Domains\Qna\Policies\QuestionAnswerPolicy;
use App\Domains\Qna\Search\AcceptedAnswerIndexableContentAdapter;
use App\Platform\Shared\Providers\BaseDomainServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

/**
 * Wires the Q&A module by convention (BaseDomainServiceProvider): its migrations, its route file, and
 * its two policies. No container bindings of its own — it consumes existing Platform ports
 * (CourseAccessPort, CourseEnrollmentPort, UserLookupPort) and the shared moderation substrate.
 *
 * Register this in bootstrap/providers.php:  App\Domains\Qna\Providers\QnaServiceProvider::class
 * (left to the integrator, per the task constraints).
 */
class QnaServiceProvider extends BaseDomainServiceProvider
{
    /** @var list<string> */
    protected array $routeFiles = [
        'routes/qna.php',
    ];

    /** @var array<class-string, class-string> */
    protected array $policies = [
        CourseQuestion::class => CourseQuestionPolicy::class,
        QuestionAnswer::class => QuestionAnswerPolicy::class,
    ];

    protected function domainPath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        // Search: expose accepted Q&A answers to the RAG index (authenticated-audience knowledge).
        // Tagged so the Search ingestion service discovers it without referencing Qna.
        $this->app->tag([AcceptedAnswerIndexableContentAdapter::class], 'search.indexers');

        $this->commands([SendOverdueQuestionRemindersCommand::class]);
        $this->registerSchedule();
    }

    /**
     * A daily nudge, not an alarm: a team told hourly about the same backlog stops reading the
     * channel. Every notice is deduplicated per (question, recipient), so a missed day is caught up
     * on the next run and a double run sends nothing twice.
     */
    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('qna:send-overdue-reminders')
                ->dailyAt('08:00')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    protected function bootDomain(): void
    {
        // Notify a question's author when a new answer arrives (self-answer skipped). The subscriber
        // reaches Notifications only through the Shared LearningNotificationPort, so no
        // Notifications<->Qna Deptrac edge is introduced.
        Event::subscribe(QnaNotificationSubscriber::class);
    }
}
