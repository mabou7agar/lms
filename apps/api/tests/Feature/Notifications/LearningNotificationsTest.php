<?php

use App\Domains\Assessment\Events\AssignmentChangesRequested;
use App\Domains\Assessment\Events\AssignmentGradeReleased;
use App\Domains\Assessment\Events\AttemptGraded;
use App\Domains\Assessment\Listeners\AssessmentNotificationSubscriber;
use App\Domains\Catalog\Models\Course;
use App\Domains\Forum\Events\ForumPostCreated;
use App\Domains\Forum\Listeners\ForumNotificationSubscriber;
use App\Domains\Forum\Models\ForumThread;
use App\Domains\Qna\Events\QuestionAnswered;
use App\Domains\Qna\Listeners\QnaNotificationSubscriber;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Notifications\Database\Seeders\NotificationsSeeder;
use App\Platform\Notifications\Enums\NotificationCategory;
use App\Platform\Notifications\Jobs\DeliverNotificationJob;
use App\Platform\Notifications\Models\Notification;
use App\Platform\Notifications\Models\NotificationDelivery;
use App\Platform\Notifications\Models\NotificationPreference;
use App\Platform\Notifications\Models\UserNotificationSetting;
use App\Platform\Shared\Notifications\Contracts\LearningNotificationPort;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Learning notification wirings (Tranche 4b). Each producing domain owns a tiny subscriber that reaches
 * the Notifications context ONLY through the Shared LearningNotificationPort, so Deptrac stays at 0.
 * These tests drive the domain subscribers directly with the real port binding (the same instance the
 * providers register) so they assert the true dispatcher path — category, template key, deterministic
 * dedup, locale, consent — without pulling in unrelated cross-domain listeners.
 */
beforeEach(function (): void {
    $this->seed(IdentitySeeder::class);      // roles + super admin
    $this->seed(NotificationsSeeder::class); // en + ar templates for the learning keys
    app(TenantContext::class)->forget();     // no tenant resolved → CourseTenantScope dormant
});

afterEach(fn () => app(TenantContext::class)->forget());

function learningPort(): LearningNotificationPort
{
    return app(LearningNotificationPort::class);
}

function assessmentSubscriber(): AssessmentNotificationSubscriber
{
    return new AssessmentNotificationSubscriber(learningPort());
}

function qnaSubscriber(): QnaNotificationSubscriber
{
    return new QnaNotificationSubscriber(learningPort());
}

function forumSubscriber(): ForumNotificationSubscriber
{
    return app(ForumNotificationSubscriber::class);
}

// ---------------------------------------------------------------- assignment grade released

it('notifies the learner once when an assignment grade is released, and dedups a repeat event', function () {
    Queue::fake();
    $learner = User::factory()->create();

    $event = new AssignmentGradeReleased(
        submissionId: 501,
        assignmentId: 10,
        courseId: 3,
        lessonId: null,
        userId: $learner->id,
        passed: true,
    );

    assessmentSubscriber()->onAssignmentGradeReleased($event);
    assessmentSubscriber()->onAssignmentGradeReleased($event); // duplicate event

    $notifications = Notification::where('user_id', $learner->id)->where('type', 'assignment_graded')->get();

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->dedup_key)->toBe('assignment-graded:501')
        ->and($notifications->first()->category)->toBe(NotificationCategory::Learning);
    Queue::assertPushed(DeliverNotificationJob::class, 1);
});

// ---------------------------------------------------------------- assignment changes requested

it('notifies the learner when changes are requested on their assignment', function () {
    Queue::fake();
    $learner = User::factory()->create();

    assessmentSubscriber()->onAssignmentChangesRequested(new AssignmentChangesRequested(
        submissionId: 77,
        assignmentId: 10,
        userId: $learner->id,
        graderId: 999,
    ));

    $notifications = Notification::where('user_id', $learner->id)->where('type', 'assignment_changes_requested')->get();

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->dedup_key)->toBe('assignment-changes-requested:77');
    Queue::assertPushed(DeliverNotificationJob::class, 1);
});

// ---------------------------------------------------------------- quiz attempt pass / fail

it('notifies the learner assessment_passed on a passing attempt, deduped per attempt', function () {
    Queue::fake();
    $learner = User::factory()->create();

    $event = new AttemptGraded(
        attemptId: 88,
        learnerUserId: $learner->id,
        assessmentId: 5,
        courseId: 3,
        passed: true,
        score: 90.0,
    );

    assessmentSubscriber()->onAttemptGraded($event);
    assessmentSubscriber()->onAttemptGraded($event); // duplicate

    $notifications = Notification::where('user_id', $learner->id)->get();

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->type)->toBe('assessment_passed')
        ->and($notifications->first()->dedup_key)->toBe('assessment-graded:88');
    Queue::assertPushed(DeliverNotificationJob::class, 1);
});

it('notifies the learner assessment_failed on a failing attempt', function () {
    Queue::fake();
    $learner = User::factory()->create();

    assessmentSubscriber()->onAttemptGraded(new AttemptGraded(
        attemptId: 89,
        learnerUserId: $learner->id,
        assessmentId: 5,
        courseId: 3,
        passed: false,
        score: 10.0,
    ));

    expect(Notification::where('user_id', $learner->id)->where('type', 'assessment_failed')->count())->toBe(1);
    Queue::assertPushed(DeliverNotificationJob::class, 1);
});

// ---------------------------------------------------------------- q&a answered

it('notifies the question author when a different user answers, deduped per answer', function () {
    Queue::fake();
    $asker = User::factory()->create();
    $answerer = User::factory()->create();

    $event = new QuestionAnswered(
        answerId: 301,
        questionId: 20,
        courseId: 3,
        answerAuthorId: $answerer->id,
        questionAuthorId: $asker->id,
        isInstructor: false,
    );

    qnaSubscriber()->onQuestionAnswered($event);
    qnaSubscriber()->onQuestionAnswered($event); // duplicate

    $notifications = Notification::where('user_id', $asker->id)->where('type', 'qna_answered')->get();

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->dedup_key)->toBe('qna-answered:301');
    Queue::assertPushed(DeliverNotificationJob::class, 1);
});

it('does not notify when the answerer is the question author (self-answer)', function () {
    Queue::fake();
    $author = User::factory()->create();

    qnaSubscriber()->onQuestionAnswered(new QuestionAnswered(
        answerId: 302,
        questionId: 20,
        courseId: 3,
        answerAuthorId: $author->id,
        questionAuthorId: $author->id,
        isInstructor: false,
    ));

    expect(Notification::count())->toBe(0);
    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------- forum reply + mention

it('notifies the thread author of a reply and each mentioned user', function () {
    Queue::fake();
    $threadAuthor = User::factory()->create();
    $replier = User::factory()->create();
    $mentioned = User::factory()->create();
    $course = Course::factory()->create();
    $thread = ForumThread::factory()->create(['course_id' => $course->id, 'user_id' => $threadAuthor->id]);

    forumSubscriber()->onForumPostCreated(new ForumPostCreated(
        postId: 900,
        threadId: $thread->id,
        courseId: $course->id,
        authorUserId: $replier->id,
        mentions: [$mentioned->public_id],
    ));

    expect(Notification::where('user_id', $threadAuthor->id)->where('type', 'forum_reply')
        ->where('dedup_key', 'forum-reply:900:user:'.$threadAuthor->id)->count())->toBe(1)
        ->and(Notification::where('user_id', $mentioned->id)->where('type', 'forum_mention')
            ->where('dedup_key', 'forum-mention:900:user:'.$mentioned->id)->count())->toBe(1);
    Queue::assertPushed(DeliverNotificationJob::class, 2);
});

it('dedups a re-delivered forum reply per post and recipient', function () {
    Queue::fake();
    $threadAuthor = User::factory()->create();
    $replier = User::factory()->create();
    $course = Course::factory()->create();
    $thread = ForumThread::factory()->create(['course_id' => $course->id, 'user_id' => $threadAuthor->id]);

    $event = new ForumPostCreated(
        postId: 901,
        threadId: $thread->id,
        courseId: $course->id,
        authorUserId: $replier->id,
        mentions: [],
    );

    forumSubscriber()->onForumPostCreated($event);
    forumSubscriber()->onForumPostCreated($event);

    expect(Notification::where('type', 'forum_reply')->count())->toBe(1);
    Queue::assertPushed(DeliverNotificationJob::class, 1);
});

it('does not notify the thread author for replying to their own thread', function () {
    Queue::fake();
    $author = User::factory()->create();
    $course = Course::factory()->create();
    $thread = ForumThread::factory()->create(['course_id' => $course->id, 'user_id' => $author->id]);

    forumSubscriber()->onForumPostCreated(new ForumPostCreated(
        postId: 902,
        threadId: $thread->id,
        courseId: $course->id,
        authorUserId: $author->id,
        mentions: [],
    ));

    expect(Notification::where('type', 'forum_reply')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('does not send a forum_mention to yourself', function () {
    Queue::fake();
    $threadAuthor = User::factory()->create();
    $replier = User::factory()->create();
    $course = Course::factory()->create();
    $thread = ForumThread::factory()->create(['course_id' => $course->id, 'user_id' => $threadAuthor->id]);

    // The replier @mentions their own public_id — the mention to self must be skipped (the thread
    // author still gets a normal forum_reply, which is a different recipient/template).
    forumSubscriber()->onForumPostCreated(new ForumPostCreated(
        postId: 903,
        threadId: $thread->id,
        courseId: $course->id,
        authorUserId: $replier->id,
        mentions: [$replier->public_id],
    ));

    expect(Notification::where('type', 'forum_mention')->count())->toBe(0)
        ->and(Notification::where('user_id', $replier->id)->count())->toBe(0);
});

// ---------------------------------------------------------------- locale

it('renders the notification in the recipient locale (ar)', function () {
    Queue::fake();
    $learner = User::factory()->create();
    UserNotificationSetting::create(['user_id' => $learner->id, 'locale' => 'ar']);

    assessmentSubscriber()->onAttemptGraded(new AttemptGraded(
        attemptId: 70,
        learnerUserId: $learner->id,
        assessmentId: 5,
        courseId: 3,
        passed: true,
        score: 88.0,
    ));

    $notification = Notification::where('user_id', $learner->id)->firstOrFail();

    expect($notification->locale)->toBe('ar')
        ->and($notification->title)->toBe('لقد نجحت'); // ar subject for assessment_passed
});

// ---------------------------------------------------------------- consent / preferences

it('suppresses a channel the learner opted out of while still delivering in-app', function () {
    Queue::fake();
    config()->set('notifications.default_channels', ['in_app', 'email']);
    config()->set('notifications.providers.mail', 'mailgun');
    config()->set('services.mailgun', ['domain' => 'mg.test', 'secret' => 'key-x', 'from' => 'no-reply@test']);

    $learner = User::factory()->create();
    NotificationPreference::create([
        'user_id' => $learner->id,
        'category' => NotificationCategory::Learning->value,
        'channel' => 'email',
        'enabled' => false,
    ]);

    assessmentSubscriber()->onAttemptGraded(new AttemptGraded(
        attemptId: 71,
        learnerUserId: $learner->id,
        assessmentId: 5,
        courseId: 3,
        passed: true,
        score: 88.0,
    ));

    $notification = Notification::where('user_id', $learner->id)->firstOrFail();

    expect(NotificationDelivery::where('notification_id', $notification->id)->where('channel', 'email')->count())->toBe(0)
        ->and(NotificationDelivery::where('notification_id', $notification->id)->where('channel', 'in_app')->count())->toBe(1);
});

it('delivers the opted-in channel for the same flow when the learner has not opted out', function () {
    Queue::fake();
    config()->set('notifications.default_channels', ['in_app', 'email']);
    config()->set('notifications.providers.mail', 'mailgun');
    config()->set('services.mailgun', ['domain' => 'mg.test', 'secret' => 'key-x', 'from' => 'no-reply@test']);

    $learner = User::factory()->create();

    assessmentSubscriber()->onAttemptGraded(new AttemptGraded(
        attemptId: 72,
        learnerUserId: $learner->id,
        assessmentId: 5,
        courseId: 3,
        passed: true,
        score: 88.0,
    ));

    $notification = Notification::where('user_id', $learner->id)->firstOrFail();

    expect(NotificationDelivery::where('notification_id', $notification->id)->where('channel', 'email')->count())->toBe(1);
});
