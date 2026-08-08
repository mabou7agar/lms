<?php

declare(strict_types=1);

use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Models\Course;
use App\Domains\Qna\Enums\QuestionStatus;
use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QuestionAnswer;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Enums\Role;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Moderation\Enums\ReportReason;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

/** A user holding the instructor role (web guard, unaffected by an active Sanctum guard). */
function qnaInstructor(): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName(Role::Instructor->value, 'web'));

    return $user;
}

/** A published course trained by $instructor. */
function qnaCourse(User $instructor): Course
{
    $course = Course::factory()->published()->create();
    $course->syncTrainers([$instructor->id]);

    return $course;
}

/** Enrol $user in $course (active by default). */
function qnaEnrol(User $user, Course $course): Enrollment
{
    return Enrollment::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);
}

// ---------------------------------------------------------------------------------------------
// Asking.
// ---------------------------------------------------------------------------------------------

it('lets an enrolled learner ask a question', function (): void {
    $course = qnaCourse(qnaInstructor());
    $learner = User::factory()->create();
    qnaEnrol($learner, $course);

    Sanctum::actingAs($learner);
    $res = $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'How do I submit?',
        'body' => '<p>Where is the button?</p>',
    ])->assertCreated();

    expect($res->json('data.title'))->toBe('How do I submit?');
    $this->assertDatabaseHas('course_questions', [
        'course_id' => $course->id,
        'user_id' => $learner->id,
        'title' => 'How do I submit?',
        'status' => QuestionStatus::Open->value,
    ]);
});

it('forbids a non-enrolled user from asking (negative)', function (): void {
    $course = qnaCourse(qnaInstructor());
    $outsider = User::factory()->create();

    Sanctum::actingAs($outsider);
    $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'Sneaky', 'body' => 'Let me in',
    ])->assertForbidden();

    $this->assertDatabaseCount('course_questions', 0);
});

it('strips <script> from a question body on write (sanitization)', function (): void {
    $course = qnaCourse(qnaInstructor());
    $learner = User::factory()->create();
    qnaEnrol($learner, $course);

    Sanctum::actingAs($learner);
    $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'XSS',
        'body' => '<script>alert(1)</script><p>hello</p>',
    ])->assertCreated();

    $body = CourseQuestion::query()->latest('id')->first()->body;
    expect($body)->not->toContain('<script>')
        ->and($body)->toContain('hello');
});

// ---------------------------------------------------------------------------------------------
// Answering + the is_instructor badge.
// ---------------------------------------------------------------------------------------------

it('flags an instructor answer with is_instructor', function (): void {
    $instructor = qnaInstructor();
    $course = qnaCourse($instructor);
    $learner = User::factory()->create();
    qnaEnrol($learner, $course);
    $question = CourseQuestion::factory()->create(['course_id' => $course->id, 'user_id' => $learner->id]);

    Sanctum::actingAs($instructor);
    $res = $this->postJson("/api/v1/questions/{$question->public_id}/answers", [
        'body' => '<p>Here is how.</p>',
    ])->assertCreated();

    expect($res->json('data.is_instructor'))->toBeTrue();
    $this->assertDatabaseHas('question_answers', [
        'question_id' => $question->id, 'user_id' => $instructor->id, 'is_instructor' => true,
    ]);
    expect($question->fresh()->answers_count)->toBe(1);
});

it('records a learner answer without the instructor badge', function (): void {
    $course = qnaCourse(qnaInstructor());
    $learner = User::factory()->create();
    $answerer = User::factory()->create();
    qnaEnrol($learner, $course);
    qnaEnrol($answerer, $course);
    $question = CourseQuestion::factory()->create(['course_id' => $course->id, 'user_id' => $learner->id]);

    Sanctum::actingAs($answerer);
    $res = $this->postJson("/api/v1/questions/{$question->public_id}/answers", ['body' => 'try this'])
        ->assertCreated();

    expect($res->json('data.is_instructor'))->toBeFalse();
});

// ---------------------------------------------------------------------------------------------
// Accepting.
// ---------------------------------------------------------------------------------------------

it('lets the question author accept an answer, resolving the question', function (): void {
    $course = qnaCourse(qnaInstructor());
    $author = User::factory()->create();
    $answerer = User::factory()->create();
    qnaEnrol($author, $course);
    qnaEnrol($answerer, $course);
    $question = CourseQuestion::factory()->create(['course_id' => $course->id, 'user_id' => $author->id]);
    $answer = QuestionAnswer::factory()->create(['question_id' => $question->id, 'user_id' => $answerer->id]);

    Sanctum::actingAs($author);
    $this->postJson("/api/v1/answers/{$answer->public_id}/accept")->assertOk();

    $question->refresh();
    expect($question->status)->toBe(QuestionStatus::Resolved)
        ->and((int) $question->accepted_answer_id)->toBe((int) $answer->id)
        ->and($answer->fresh()->accepted)->toBeTrue();
});

it('keeps only one accepted answer per question', function (): void {
    $course = qnaCourse(qnaInstructor());
    $author = User::factory()->create();
    qnaEnrol($author, $course);
    $question = CourseQuestion::factory()->create(['course_id' => $course->id, 'user_id' => $author->id]);
    $first = QuestionAnswer::factory()->accepted()->create(['question_id' => $question->id, 'user_id' => User::factory()]);
    $question->forceFill(['accepted_answer_id' => $first->id, 'status' => QuestionStatus::Resolved->value])->save();
    $second = QuestionAnswer::factory()->create(['question_id' => $question->id, 'user_id' => User::factory()]);

    Sanctum::actingAs($author);
    $this->postJson("/api/v1/answers/{$second->public_id}/accept")->assertOk();

    expect($first->fresh()->accepted)->toBeFalse()
        ->and($second->fresh()->accepted)->toBeTrue()
        ->and((int) $question->fresh()->accepted_answer_id)->toBe((int) $second->id);
});

it('forbids a non-author/non-instructor from accepting (negative)', function (): void {
    $course = qnaCourse(qnaInstructor());
    $author = User::factory()->create();
    $bystander = User::factory()->create();
    qnaEnrol($author, $course);
    qnaEnrol($bystander, $course);
    $question = CourseQuestion::factory()->create(['course_id' => $course->id, 'user_id' => $author->id]);
    $answer = QuestionAnswer::factory()->create(['question_id' => $question->id, 'user_id' => $bystander->id]);

    Sanctum::actingAs($bystander);
    $this->postJson("/api/v1/answers/{$answer->public_id}/accept")->assertForbidden();

    expect($question->fresh()->status)->toBe(QuestionStatus::Open);
});

// ---------------------------------------------------------------------------------------------
// Pinning.
// ---------------------------------------------------------------------------------------------

it('lets an instructor pin but forbids a learner (authz)', function (): void {
    $instructor = qnaInstructor();
    $course = qnaCourse($instructor);
    $learner = User::factory()->create();
    qnaEnrol($learner, $course);
    $question = CourseQuestion::factory()->create(['course_id' => $course->id, 'user_id' => $learner->id]);

    Sanctum::actingAs($learner);
    $this->postJson("/api/v1/questions/{$question->public_id}/pin")->assertForbidden();

    Sanctum::actingAs($instructor);
    $this->postJson("/api/v1/questions/{$question->public_id}/pin")->assertOk();

    expect($question->fresh()->pinned_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// Ownership / IDOR.
// ---------------------------------------------------------------------------------------------

it('lets a learner edit/delete only their own question (IDOR negative)', function (): void {
    $course = qnaCourse(qnaInstructor());
    $owner = User::factory()->create();
    $other = User::factory()->create();
    qnaEnrol($owner, $course);
    qnaEnrol($other, $course);
    $question = CourseQuestion::factory()->create(['course_id' => $course->id, 'user_id' => $owner->id]);

    Sanctum::actingAs($other);
    $this->patchJson("/api/v1/questions/{$question->public_id}", ['title' => 'Hijacked'])->assertForbidden();
    $this->deleteJson("/api/v1/questions/{$question->public_id}")->assertForbidden();

    Sanctum::actingAs($owner);
    $this->patchJson("/api/v1/questions/{$question->public_id}", ['title' => 'Mine edited'])->assertOk();
    $this->deleteJson("/api/v1/questions/{$question->public_id}")->assertOk();

    $this->assertSoftDeleted('course_questions', ['id' => $question->id]);
});

// ---------------------------------------------------------------------------------------------
// Filtering.
// ---------------------------------------------------------------------------------------------

it('filters to unanswered questions', function (): void {
    $course = qnaCourse(qnaInstructor());
    $learner = User::factory()->create();
    qnaEnrol($learner, $course);
    $answered = CourseQuestion::factory()->create(['course_id' => $course->id, 'user_id' => $learner->id, 'answers_count' => 1]);
    $unanswered = CourseQuestion::factory()->create(['course_id' => $course->id, 'user_id' => $learner->id, 'answers_count' => 0]);

    Sanctum::actingAs($learner);
    $ids = collect(
        $this->getJson("/api/v1/courses/{$course->public_id}/questions?sort=unanswered")->assertOk()->json('data')
    )->pluck('id');

    expect($ids)->toContain($unanswered->public_id)
        ->and($ids)->not->toContain($answered->public_id);
});

// ---------------------------------------------------------------------------------------------
// Reporting (shared moderation substrate).
// ---------------------------------------------------------------------------------------------

it('creates a content report when a participant reports a question', function (): void {
    $course = qnaCourse(qnaInstructor());
    $learner = User::factory()->create();
    $reporter = User::factory()->create();
    qnaEnrol($learner, $course);
    qnaEnrol($reporter, $course);
    $question = CourseQuestion::factory()->create(['course_id' => $course->id, 'user_id' => $learner->id]);

    Sanctum::actingAs($reporter);
    $this->postJson("/api/v1/questions/{$question->public_id}/report", [
        'reason' => ReportReason::cases()[0]->value,
    ])->assertOk();

    expect($question->reports()->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// Tenancy (T1 Option-N global-OR-own-org).
// ---------------------------------------------------------------------------------------------

it('hides an org1 course question from an org2 tenant (model boundary)', function (): void {
    $context = app(TenantContext::class);

    $context->set(TenantId::from(1));
    $org1Course = Course::factory()->create();
    $context->forget();

    $author = User::factory()->create();
    $question = CourseQuestion::factory()->create(['course_id' => $org1Course->id, 'user_id' => $author->id]);

    // Under org2 the org1-private course — and its questions — are invisible.
    $context->set(TenantId::from(2));
    expect(CourseQuestion::where('course_id', $org1Course->id)->count())->toBe(0)
        ->and(CourseQuestion::find($question->id))->toBeNull();

    // And still visible to org1.
    $context->set(TenantId::from(1));
    expect(CourseQuestion::find($question->id))->not->toBeNull();
});

it('refuses to create a question on another org\'s private course over HTTP (tenancy)', function (): void {
    $context = app(TenantContext::class);

    $context->set(TenantId::from(1));
    $org1Course = Course::factory()->published()->create();
    $context->forget();

    $org2User = User::factory()->create(['organization_id' => 2]);
    $context->set(TenantId::from(2));

    Sanctum::actingAs($org2User);
    $this->postJson("/api/v1/courses/{$org1Course->public_id}/questions", [
        'title' => 'cross tenant', 'body' => 'nope',
    ])->assertNotFound();

    $this->assertDatabaseCount('course_questions', 0);
});
