<?php

use App\Domains\Assessment\Models\Assessment;
use App\Domains\Authoring\Enums\LessonType;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Contracts\CoursePublishGuard;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Publishing\Data\CourseReadinessInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
});

/** A course that passes every rule, so each test can break exactly one thing. */
function readyCourse(): array
{
    $course = Course::factory()->create([
        'status' => CourseStatus::Draft,
        'description' => 'A real description.',
        'thumbnail_path' => 'courses/thumb.jpg',
    ]);

    $instructor = User::factory()->create();
    $instructor->assignRole(SpatieRole::findByName('instructor', 'web'));
    $course->syncTrainers([$instructor->id]);

    $section = Section::factory()->create([
        'course_id' => $course->id,
        'publish_state' => PublishState::Published->value,
    ]);

    Lesson::factory()->create([
        'section_id' => $section->id,
        'type' => LessonType::Article->value,
        'publish_state' => PublishState::Published->value,
        'content' => ['html' => '<p>Body</p>'],
    ]);

    return [$course, $instructor, $section];
}

function readinessInput(Course $course): CourseReadinessInput
{
    return new CourseReadinessInput(
        courseId: (int) $course->getKey(),
        coursePublicId: (string) $course->getAttribute('public_id'),
        description: $course->getAttribute('description'),
        thumbnailPath: $course->getAttribute('thumbnail_path'),
        hasInstructor: $course->trainerLinks()->exists(),
        visibility: $course->getAttribute('visibility')?->value,
    );
}

function reportFor(Course $course)
{
    return app(CoursePublishGuard::class)->report(readinessInput($course));
}

it('reports a fully prepared course as publishable with a perfect score', function () {
    [$course] = readyCourse();

    $report = reportFor($course);

    expect($report->isPublishable())->toBeTrue()
        ->and($report->blockers())->toBeEmpty()
        ->and($report->warnings())->toBeEmpty()
        ->and($report->score())->toBe(100);
});

it('blocks a course with no sections and stops there', function () {
    $course = Course::factory()->create(['description' => 'x', 'thumbnail_path' => 'x.jpg']);

    $report = reportFor($course);

    expect($report->isPublishable())->toBeFalse();

    // One cause, one issue: the lesson rules are not reported as separate failures when the real
    // problem is that there is nowhere for a lesson to live.
    $codes = array_map(fn ($i) => $i->code, $report->blockers());
    expect($codes)->toBe(['course.no_sections']);
});

it('blocks a course whose lessons are all drafts', function () {
    [$course, , $section] = readyCourse();
    Lesson::where('section_id', $section->id)->update(['publish_state' => PublishState::Draft->value]);

    $report = reportFor($course);

    expect($report->isPublishable())->toBeFalse()
        ->and(array_map(fn ($i) => $i->code, $report->blockers()))->toContain('course.no_published_lesson');
});

it('warns about a published lesson that has neither content nor media, without blocking', function () {
    [$course, , $section] = readyCourse();
    Lesson::factory()->create([
        'section_id' => $section->id,
        'title' => 'Hollow lesson',
        'type' => LessonType::Article->value,
        'publish_state' => PublishState::Published->value,
        'content' => null,
    ]);

    $report = reportFor($course);
    $warning = collect($report->warnings())->firstWhere('code', 'lesson.empty_content');

    // Deliberately NOT a blocker. Publishing has never required lesson content, so making this
    // fatal would strand every existing course with a thin lesson — authors penalised for a rule
    // that did not exist when they published.
    expect($report->isPublishable())->toBeTrue()
        ->and($warning)->not->toBeNull()
        ->and($warning->title)->toContain('Hollow lesson')
        ->and($warning->entityType)->toBe('lesson');
});

it('leaves an empty DRAFT lesson alone', function () {
    [$course, , $section] = readyCourse();
    Lesson::factory()->create([
        'section_id' => $section->id,
        'type' => LessonType::Article->value,
        'publish_state' => PublishState::Draft->value,
        'content' => null,
    ]);

    // Unfinished work parked in draft is the point of draft, not a publishing defect — and it is
    // not even worth warning about.
    $report = reportFor($course);

    expect($report->isPublishable())->toBeTrue()
        ->and(array_map(fn ($i) => $i->code, $report->warnings()))->not->toContain('lesson.empty_content');
});

it('blocks a quiz lesson with no assessment attached', function () {
    [$course, , $section] = readyCourse();
    Lesson::factory()->create([
        'section_id' => $section->id,
        'title' => 'Module check',
        'type' => LessonType::Quiz->value,
        'publish_state' => PublishState::Published->value,
        'assessment_id' => null,
    ]);

    $blocker = collect(reportFor($course)->blockers())->firstWhere('code', 'lesson.quiz_without_published_assessment');

    expect($blocker)->not->toBeNull()
        ->and($blocker->explanation)->toContain('No quiz is attached');
});

it('blocks a quiz lesson whose assessment is still a draft', function () {
    [$course, , $section] = readyCourse();
    $assessment = Assessment::factory()->create(['course_id' => $course->id, 'status' => 'draft']);
    Lesson::factory()->create([
        'section_id' => $section->id,
        'type' => LessonType::Quiz->value,
        'publish_state' => PublishState::Published->value,
        'assessment_id' => $assessment->id,
    ]);

    // Matches what the learner would actually see: the publish-gated reference resolves to null,
    // so the lesson renders as unavailable.
    $blocker = collect(reportFor($course)->blockers())->firstWhere('code', 'lesson.quiz_without_published_assessment');

    expect($blocker)->not->toBeNull()
        ->and($blocker->explanation)->toContain('still a draft');
});

it('accepts a quiz lesson with a published assessment', function () {
    [$course, , $section] = readyCourse();
    $assessment = Assessment::factory()->create(['course_id' => $course->id, 'status' => 'published']);
    Lesson::factory()->create([
        'section_id' => $section->id,
        'type' => LessonType::Quiz->value,
        'publish_state' => PublishState::Published->value,
        'assessment_id' => $assessment->id,
    ]);

    expect(reportFor($course)->isPublishable())->toBeTrue();
});

it('warns about thin metadata without blocking', function () {
    [$course] = readyCourse();
    $course->forceFill(['description' => '', 'thumbnail_path' => null])->save();

    $report = reportFor($course);
    $codes = array_map(fn ($i) => $i->code, $report->warnings());

    expect($report->isPublishable())->toBeTrue()
        ->and($codes)->toContain('course.missing_description')
        ->and($codes)->toContain('course.missing_thumbnail')
        ->and($report->score())->toBeLessThan(100);
});

it('warns when no instructor is assigned', function () {
    [$course, $instructor] = readyCourse();
    $course->syncTrainers([]);

    $report = reportFor($course);

    expect($report->isPublishable())->toBeTrue()
        ->and(array_map(fn ($i) => $i->code, $report->warnings()))->toContain('course.no_instructor');
});

it('keeps the guard verdict identical to the report it exposes', function () {
    [$course, , $section] = readyCourse();
    Lesson::where('section_id', $section->id)->update(['publish_state' => PublishState::Draft->value]);

    $guard = app(CoursePublishGuard::class);

    // The whole point of the shared report: a panel saying "ready" while publish refuses would be
    // worse than no panel at all.
    $verdict = $guard->canPublish($course);
    $report = $guard->report(readinessInput($course));

    expect($verdict)->toBe($report->isPublishable())
        ->and($guard->reason())->toBe($report->firstBlockerReason());
});

it('exposes readiness to the owning instructor over the API', function () {
    [$course, $instructor] = readyCourse();

    $this->actingAs($instructor, 'sanctum')
        ->getJson("/api/v1/teach/courses/{$course->public_id}/readiness")
        ->assertOk()
        ->assertJsonPath('data.is_publishable', true)
        ->assertJsonPath('data.score', 100)
        ->assertJsonStructure([
            'data' => ['is_publishable', 'score', 'evaluated_at', 'blockers', 'warnings', 'passed_checks'],
        ]);
});

it('returns every issue field the panel needs to act on', function () {
    [$course, $instructor, $section] = readyCourse();
    Lesson::where('section_id', $section->id)->update(['publish_state' => PublishState::Draft->value]);

    $blocker = $this->actingAs($instructor, 'sanctum')
        ->getJson("/api/v1/teach/courses/{$course->public_id}/readiness")
        ->assertOk()
        ->json('data.blockers.0');

    expect(array_keys($blocker))->toEqualCanonicalizing(
        ['code', 'severity', 'title', 'explanation', 'recommended_action', 'entity_type', 'entity_id'],
    );
});

it('hides readiness for a course the caller does not train', function () {
    [$course] = readyCourse();
    $stranger = User::factory()->create();
    $stranger->assignRole(SpatieRole::findByName('instructor', 'web'));

    // 404 not 403, matching every other instructor-portal route: non-owned is indistinguishable
    // from missing, so an instructor cannot probe for courses they do not train.
    $this->actingAs($stranger, 'sanctum')
        ->getJson("/api/v1/teach/courses/{$course->public_id}/readiness")
        ->assertNotFound();
});

it('refuses readiness to a learner', function () {
    [$course] = readyCourse();

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/teach/courses/{$course->public_id}/readiness")
        ->assertForbidden();
});

it('refuses the real publish when readiness reports a blocker', function () {
    [$course, $instructor, $section] = readyCourse();
    Lesson::where('section_id', $section->id)->update(['publish_state' => PublishState::Draft->value]);

    $this->actingAs($instructor, 'sanctum')
        ->postJson("/api/v1/teach/courses/{$course->public_id}/publish")
        ->assertStatus(422);

    expect($course->refresh()->status)->toBe(CourseStatus::Draft);
});

// ---------------------------------------------------------------- visibility

it('passes visibility when the course is public', function () {
    [$course] = readyCourse();

    expect(reportFor($course)->passedChecks)->toContain('course.not_publicly_visible');
});

it('warns without blocking when a course is not publicly visible', function (string $visibility) {
    [$course] = readyCourse();
    $course->forceFill(['visibility' => $visibility])->save();

    $report = reportFor($course->refresh());

    // Private and unlisted courses are a legitimate way to ship — internal or cohort-gated content
    // is published on purpose. Blocking would break that workflow and would retroactively stop
    // every already-published non-public course from re-publishing.
    expect($report->isPublishable())->toBeTrue()
        ->and(array_column($report->warnings(), 'code'))->toContain('course.not_publicly_visible')
        ->and(array_column($report->blockers(), 'code'))->not->toContain('course.not_publicly_visible');
})->with(['private', 'unlisted', 'hidden']);

it('names the offending visibility so the author can act on it', function () {
    [$course] = readyCourse();
    $course->forceFill(['visibility' => 'private'])->save();

    $issue = collect(reportFor($course->refresh())->warnings())
        ->firstWhere('code', 'course.not_publicly_visible');

    expect($issue->title)->toContain('private')
        ->and($issue->recommendedAction)->not->toBeEmpty()
        ->and($issue->entityType)->toBe('course')
        ->and($issue->entityPublicId)->toBe($course->public_id);
});

it('reports an unrecognised visibility separately rather than ignoring it', function (?string $visibility) {
    [$course] = readyCourse();

    // Exercised at the DTO, not through Eloquent, and deliberately so: Course casts `visibility` to
    // the Visibility enum, so an unknown value cannot survive a save — the model layer already
    // makes that state unreachable. What IS reachable is a CALLER handing the evaluator a value the
    // enum does not know, including the null default on the input DTO. That is the case this rule
    // exists for, and this is where it can actually be reached.
    $report = app(CoursePublishGuard::class)->report(new CourseReadinessInput(
        courseId: (int) $course->getKey(),
        coursePublicId: (string) $course->getAttribute('public_id'),
        description: $course->getAttribute('description'),
        thumbnailPath: $course->getAttribute('thumbnail_path'),
        hasInstructor: true,
        visibility: $visibility,
    ));

    expect(array_column($report->warnings(), 'code'))->toContain('course.invalid_visibility')
        ->and($report->isPublishable())->toBeTrue();
})->with(['sideways', '', null]);

it('still allows the real publish for a private course', function () {
    [$course, $instructor] = readyCourse();
    $course->forceFill(['visibility' => 'private'])->save();

    $this->actingAs($instructor, 'sanctum')
        ->postJson("/api/v1/teach/courses/{$course->public_id}/publish")
        ->assertOk();

    expect($course->refresh()->status)->toBe(CourseStatus::Published);
});

it('surfaces the visibility warning through the readiness endpoint', function () {
    [$course, $instructor] = readyCourse();
    $course->forceFill(['visibility' => 'private'])->save();

    $body = $this->actingAs($instructor, 'sanctum')
        ->getJson("/api/v1/teach/courses/{$course->public_id}/readiness")
        ->assertOk()
        ->json('data');

    expect(json_encode($body))->toContain('course.not_publicly_visible');
});
