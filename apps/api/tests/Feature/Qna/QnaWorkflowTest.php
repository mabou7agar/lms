<?php

declare(strict_types=1);

use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseTrainer;
use App\Domains\Qna\Enums\QuestionStatus;
use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QnaSetting;
use App\Domains\Qna\Models\QuestionAnswer;
use App\Domains\Qna\Services\QnaMetricsService;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** A published course with one lesson, an instructor who teaches it, and an enrolled learner. */
function qnaFixture(): array
{
    $course = Course::factory()->published()->create();
    $section = Section::factory()->published()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->published()->create(['section_id' => $section->id]);

    $instructor = User::factory()->create();
    CourseTrainer::create(['course_id' => $course->id, 'user_id' => $instructor->id]);

    $learner = User::factory()->create();
    qnaWorkflowEnrol($course, $learner);

    return [$course, $lesson, $instructor, $learner];
}

function qnaWorkflowEnrol(Course $course, User $user): Enrollment
{
    return Enrollment::create([
        'user_id' => $user->id,
        'course_id' => $course->id,
        'status' => EnrollmentStatus::Active->value,
        'source' => EnrollmentSource::Purchase->value,
        'enrolled_at' => now(),
    ]);
}

// ── Asking ───────────────────────────────────────────────────────────────────────────────────────

it('lets an enrolled learner ask a course question', function (): void {
    [$course, , , $learner] = qnaFixture();
    Sanctum::actingAs($learner);

    $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'How does the discount work?',
        'body' => 'I did not follow the worked example.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.visibility', 'public')
        ->assertJsonPath('data.awaiting_response', true);
});

it('lets a learner ask about one lesson', function (): void {
    [$course, $lesson, , $learner] = qnaFixture();
    Sanctum::actingAs($learner);

    $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'Timecode 4:20',
        'body' => 'What tool is on screen here?',
        'lesson_id' => $lesson->public_id,
        'lesson_timestamp_seconds' => 260,
    ])->assertCreated();

    Sanctum::actingAs($learner);

    $this->getJson("/api/v1/courses/{$course->public_id}/questions?lesson_id={$lesson->public_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('denies a non-participant the course questions', function (): void {
    [$course] = qnaFixture();
    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/courses/{$course->public_id}/questions")->assertForbidden();
});

// ── Private threads ──────────────────────────────────────────────────────────────────────────────

it('hides a private question from other learners but not from its asker or the instructor', function (): void {
    [$course, , $instructor, $learner] = qnaFixture();
    $classmate = User::factory()->create();
    qnaWorkflowEnrol($course, $classmate);

    Sanctum::actingAs($learner);
    $created = $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'Something I would rather not ask publicly',
        'body' => 'Private detail.',
        'visibility' => 'private',
    ])->assertCreated()->json('data.id');

    // The asker still sees their own thread.
    $this->getJson("/api/v1/questions/{$created}")->assertOk();
    $this->getJson("/api/v1/courses/{$course->public_id}/questions")->assertOk()->assertJsonCount(1, 'data');

    // A classmate sees neither the listing entry nor the thread.
    Sanctum::actingAs($classmate);
    $this->getJson("/api/v1/courses/{$course->public_id}/questions")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/questions/{$created}")->assertForbidden();

    // The course team is exactly who a private question is addressed to.
    Sanctum::actingAs($instructor);
    $this->getJson("/api/v1/questions/{$created}")->assertOk();
});

// ── Answering + response metrics ─────────────────────────────────────────────────────────────────

it('stamps the response clock when the instructor answers', function (): void {
    [$course, , $instructor, $learner] = qnaFixture();

    Sanctum::actingAs($learner);
    $questionId = $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'Waiting on you', 'body' => 'Please advise.',
    ])->assertCreated()->json('data.id');

    $question = CourseQuestion::where('public_id', $questionId)->firstOrFail();
    // Asked two hours ago, so the elapsed minutes are a real number rather than zero.
    $question->forceFill(['created_at' => now()->subHours(2)])->save();

    Sanctum::actingAs($instructor);
    $this->postJson("/api/v1/questions/{$questionId}/answers", ['body' => 'Here is the answer.'])
        ->assertCreated()
        ->assertJsonPath('data.is_instructor', true);

    $question->refresh();

    expect($question->first_response_at)->not->toBeNull()
        ->and($question->first_response_minutes)->toBeGreaterThanOrEqual(119)
        ->and($question->status)->toBe(QuestionStatus::Answered);
});

it('does not let a fellow learner answer start the response clock', function (): void {
    [$course, , , $learner] = qnaFixture();
    $classmate = User::factory()->create();
    qnaWorkflowEnrol($course, $classmate);

    Sanctum::actingAs($learner);
    $questionId = $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'Peer help', 'body' => 'Anyone?',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($classmate);
    $this->postJson("/api/v1/questions/{$questionId}/answers", ['body' => 'I think it is X.'])->assertCreated();

    $question = CourseQuestion::where('public_id', $questionId)->firstOrFail();

    // A helpful classmate is a good thing, but it is not the course team having replied.
    expect($question->first_response_at)->toBeNull()
        ->and($question->status)->toBe(QuestionStatus::Answered);
});

it('keeps the first response time when a second instructor answer arrives', function (): void {
    [$course, , $instructor, $learner] = qnaFixture();

    Sanctum::actingAs($learner);
    $questionId = $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'Two replies', 'body' => 'Question.',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($instructor);
    $this->postJson("/api/v1/questions/{$questionId}/answers", ['body' => 'First.'])->assertCreated();
    $first = CourseQuestion::where('public_id', $questionId)->firstOrFail()->first_response_at;

    $this->postJson("/api/v1/questions/{$questionId}/answers", ['body' => 'Second.'])->assertCreated();

    expect(CourseQuestion::where('public_id', $questionId)->firstOrFail()->first_response_at->toIso8601String())
        ->toBe($first->toIso8601String());
});

// ── Official + accepted ──────────────────────────────────────────────────────────────────────────

it('lets the course team mark one official answer at a time', function (): void {
    [$course, , $instructor, $learner] = qnaFixture();

    Sanctum::actingAs($learner);
    $questionId = $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'Which is right?', 'body' => 'Two views.',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($instructor);
    $a = $this->postJson("/api/v1/questions/{$questionId}/answers", ['body' => 'Answer A.'])->json('data.id');
    $b = $this->postJson("/api/v1/questions/{$questionId}/answers", ['body' => 'Answer B.'])->json('data.id');

    $this->postJson("/api/v1/answers/{$a}/official")->assertOk()->assertJsonPath('data.is_official', true);
    $this->postJson("/api/v1/answers/{$b}/official")->assertOk()->assertJsonPath('data.is_official', true);

    // Marking a new one clears the previous: a course has one authoritative answer, not several.
    expect(QuestionAnswer::where('public_id', $a)->firstOrFail()->is_official)->toBeFalse();
});

it('refuses to let a learner mark an answer official', function (): void {
    [$course, , $instructor, $learner] = qnaFixture();

    Sanctum::actingAs($learner);
    $questionId = $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'Q', 'body' => 'B',
    ])->json('data.id');

    Sanctum::actingAs($instructor);
    $answerId = $this->postJson("/api/v1/questions/{$questionId}/answers", ['body' => 'A.'])->json('data.id');

    Sanctum::actingAs($learner);
    $this->postJson("/api/v1/answers/{$answerId}/official")->assertForbidden();
});

it('lets the asker accept an answer, which resolves the thread', function (): void {
    [$course, , $instructor, $learner] = qnaFixture();

    Sanctum::actingAs($learner);
    $questionId = $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'Q', 'body' => 'B',
    ])->json('data.id');

    Sanctum::actingAs($instructor);
    $answerId = $this->postJson("/api/v1/questions/{$questionId}/answers", ['body' => 'A.'])->json('data.id');

    Sanctum::actingAs($learner);
    $this->postJson("/api/v1/answers/{$answerId}/accept")->assertOk();

    expect(CourseQuestion::where('public_id', $questionId)->firstOrFail()->status)->toBe(QuestionStatus::Resolved);
});

// ── Closing ──────────────────────────────────────────────────────────────────────────────────────

it('lets the course team close and reopen a thread', function (): void {
    [$course, , $instructor, $learner] = qnaFixture();

    Sanctum::actingAs($learner);
    $questionId = $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'Duplicate', 'body' => 'Asked before.',
    ])->json('data.id');

    Sanctum::actingAs($instructor);
    $this->postJson("/api/v1/questions/{$questionId}/close")->assertOk()->assertJsonPath('data.status', 'closed');

    // Reopening returns it to the state it had actually reached, not blindly to open.
    $this->deleteJson("/api/v1/questions/{$questionId}/close")->assertOk()->assertJsonPath('data.status', 'open');
});

// ── SLA + instructor inbox ───────────────────────────────────────────────────────────────────────

it('counts an unanswered question as overdue once the SLA has elapsed', function (): void {
    [$course, , $instructor, $learner] = qnaFixture();
    QnaSetting::current()->forceFill(['response_sla_hours' => 24])->save();

    Sanctum::actingAs($learner);
    $questionId = $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'Old question', 'body' => 'Still waiting.',
    ])->json('data.id');

    CourseQuestion::where('public_id', $questionId)->firstOrFail()
        ->forceFill(['created_at' => now()->subHours(30)])->save();

    $metrics = app(QnaMetricsService::class)->forCourses([$course->id]);

    expect($metrics['questions'])->toBe(1)
        ->and($metrics['unanswered'])->toBe(1)
        ->and($metrics['overdue'])->toBe(1)
        ->and($metrics['response_rate'])->toBe(0.0);

    Sanctum::actingAs($instructor);
    $this->getJson('/api/v1/instructor/questions?filter=overdue')
        ->assertOk()
        ->assertJsonCount(1, 'data.questions')
        ->assertJsonPath('data.metrics.overdue', 1);
});

it('stops counting a question as overdue once the instructor replies', function (): void {
    [$course, , $instructor, $learner] = qnaFixture();
    QnaSetting::current()->forceFill(['response_sla_hours' => 24])->save();

    Sanctum::actingAs($learner);
    $questionId = $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'Old question', 'body' => 'Waiting.',
    ])->json('data.id');
    CourseQuestion::where('public_id', $questionId)->firstOrFail()
        ->forceFill(['created_at' => now()->subHours(30)])->save();

    Sanctum::actingAs($instructor);
    $this->postJson("/api/v1/questions/{$questionId}/answers", ['body' => 'Sorry for the wait.'])->assertCreated();

    $metrics = app(QnaMetricsService::class)->forCourses([$course->id]);

    expect($metrics['overdue'])->toBe(0)
        ->and($metrics['answered'])->toBe(1)
        ->and($metrics['response_rate'])->toBe(1.0);
});

it('shows an instructor only the questions on courses they teach', function (): void {
    [$course, , $instructor, $learner] = qnaFixture();

    // A second course, taught by somebody else, with its own waiting question.
    [$otherCourse, , , $otherLearner] = qnaFixture();
    Sanctum::actingAs($otherLearner);
    $this->postJson("/api/v1/courses/{$otherCourse->public_id}/questions", [
        'title' => 'Not your course', 'body' => 'Hello.',
    ])->assertCreated();

    Sanctum::actingAs($learner);
    $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'Your course', 'body' => 'Hello.',
    ])->assertCreated();

    Sanctum::actingAs($instructor);

    $this->getJson('/api/v1/instructor/questions')
        ->assertOk()
        ->assertJsonCount(1, 'data.questions')
        ->assertJsonPath('data.questions.0.title', 'Your course');
});

it('gives an instructor with no courses an empty queue rather than an error', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/instructor/questions')
        ->assertOk()
        ->assertJsonPath('data.metrics.questions', 0)
        ->assertJsonCount(0, 'data.questions');
});
