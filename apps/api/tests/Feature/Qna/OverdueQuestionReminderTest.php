<?php

declare(strict_types=1);

use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseTrainer;
use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QnaSetting;
use App\Domains\Qna\Services\OverdueQuestionNotifier;
use App\Platform\Identity\Models\User;
use App\Platform\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `notify_instructor_on_overdue` was a stored setting with nothing behind it: the metric said a
 * question was overdue and nobody was ever told. These pin the escalation and, just as importantly,
 * its silence — a nightly sweep that keeps finding the same backlog must not keep announcing it.
 */
uses(RefreshDatabase::class);

/** A course with an instructor, an enrolled learner, and one unanswered question of the given age. */
function overdueFixture(int $hoursOld, int $slaHours = 24): array
{
    QnaSetting::current()->forceFill([
        'response_sla_hours' => $slaHours,
        'notify_instructor_on_overdue' => true,
    ])->save();

    $course = Course::factory()->published()->create();
    $instructor = User::factory()->create();
    CourseTrainer::create(['course_id' => $course->id, 'user_id' => $instructor->id]);

    $learner = User::factory()->create();
    Enrollment::create([
        'user_id' => $learner->id,
        'course_id' => $course->id,
        'status' => EnrollmentStatus::Active->value,
        'source' => EnrollmentSource::Purchase->value,
        'enrolled_at' => now(),
    ]);

    $question = CourseQuestion::factory()->create([
        'course_id' => $course->id,
        'user_id' => $learner->id,
        'title' => 'Still waiting',
        'answers_count' => 0,
    ]);
    $question->forceFill(['created_at' => now()->subHours($hoursOld)])->save();

    return [$course, $instructor, $question];
}

it('tells the course team about a question that has breached the promise', function (): void {
    [, $instructor] = overdueFixture(hoursOld: 30, slaHours: 24);

    expect(app(OverdueQuestionNotifier::class)->sweep())->toBe(1);

    $notification = Notification::where('user_id', $instructor->id)
        ->where('type', 'qna_question_overdue')
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['title'])->toBe('Still waiting')
        ->and((int) $notification->data['hours'])->toBeGreaterThanOrEqual(29);
});

it('says nothing twice however often the sweep runs', function (): void {
    [, $instructor] = overdueFixture(hoursOld: 30, slaHours: 24);

    $notifier = app(OverdueQuestionNotifier::class);
    $first = $notifier->sweep();
    $second = $notifier->sweep();
    $third = $notifier->sweep();

    // A question breaches its promise once. Repeating that nightly would train the team to ignore
    // the whole channel.
    expect(Notification::where('user_id', $instructor->id)->where('type', 'qna_question_overdue')->count())
        ->toBe(1);

    // And the count the operator reads reflects that: the later sweeps found the backlog and sent
    // nothing, so they must not claim to have sent anything.
    expect([$first, $second, $third])->toBe([1, 0, 0]);
});

it('stays quiet while the question is still inside the promise', function (): void {
    [, $instructor] = overdueFixture(hoursOld: 4, slaHours: 24);

    expect(app(OverdueQuestionNotifier::class)->sweep())->toBe(0)
        ->and(Notification::where('user_id', $instructor->id)->count())->toBe(0);
});

it('sends nothing when the admin has turned the escalation off', function (): void {
    [, $instructor] = overdueFixture(hoursOld: 30, slaHours: 24);
    QnaSetting::current()->forceFill(['notify_instructor_on_overdue' => false])->save();

    expect(app(OverdueQuestionNotifier::class)->sweep())->toBe(0)
        ->and(Notification::where('user_id', $instructor->id)->count())->toBe(0);
});

it('stops escalating once the course team has replied', function (): void {
    [$course, $instructor, $question] = overdueFixture(hoursOld: 30, slaHours: 24);

    // An instructor reply stamps the response clock, which is what takes the question out of the
    // overdue set — the same rule the metrics and the inbox use.
    $question->forceFill(['first_response_at' => now(), 'first_response_minutes' => 1800])->save();

    expect(app(OverdueQuestionNotifier::class)->sweep())->toBe(0)
        ->and(Notification::where('user_id', $instructor->id)->count())->toBe(0);
});

it('runs the scheduled command without error', function (): void {
    overdueFixture(hoursOld: 30, slaHours: 24);

    $this->artisan('qna:send-overdue-reminders')
        ->expectsOutputToContain('Overdue question reminders:')
        ->assertSuccessful();
});
