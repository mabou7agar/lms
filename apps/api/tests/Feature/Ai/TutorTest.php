<?php

declare(strict_types=1);

use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Platform\AI\Models\AiPrompt;
use App\Platform\AI\Models\AiUsage;
use App\Platform\Identity\Models\User;
use App\Platform\Search\Models\ContentEmbedding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['ai.enabled' => true, 'ai.default_provider' => 'fake']);
    Http::preventStrayRequests();

    // The versioned prompt the tutor resolves (feature tests seed their own; they never rely on the seeder).
    AiPrompt::factory()->create([
        'key' => 'tutor.answer', 'version' => 1, 'active' => true, 'locale' => 'en',
        'system_prompt' => 'You are a course tutor. Use ONLY the context.',
        'user_template' => 'Context: {{ context }} Question: {{ question }}',
    ]);
});

/**
 * A published course with one published lesson whose block carries $text. Returns [course, lesson].
 *
 * @return array{0: Course, 1: Lesson}
 */
function tutorLessonCourse(string $text, string $title = 'Lesson'): array
{
    $course = Course::factory()->published()->create();
    $section = Section::factory()->published()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->published()->create(['section_id' => $section->id, 'title' => $title]);
    Block::factory()->published()->withContent(['en' => ['text' => $text]])->create(['lesson_id' => $lesson->id]);

    return [$course, $lesson];
}

function tutorEnrol(Course $course): User
{
    $learner = User::factory()->create();
    Enrollment::factory()->create(['user_id' => $learner->id, 'course_id' => $course->id]);

    return $learner;
}

it('answers an enrolled learner and returns citations drawn from the course content, recording usage', function () {
    [$course, $lesson] = tutorLessonCourse('quantum entanglement links distant particles instantly');
    $learner = tutorEnrol($course);
    Artisan::call('search:backfill');

    $data = $this->actingAs($learner, 'sanctum')
        ->postJson('/api/v1/ai/tutor', [
            'course_id' => $course->public_id,
            'question' => 'quantum entanglement links distant particles instantly',
        ])
        ->assertOk()
        ->json('data');

    expect($data['refused'])->toBeFalse()
        ->and($data['label'])->toBe('AI-generated')
        ->and($data['citations'])->not->toBeEmpty()
        ->and(collect($data['citations'])->pluck('id')->all())->toContain($lesson->public_id)
        ->and(collect($data['citations'])->pluck('source_type')->all())->toContain('lesson');

    // Usage recorded against the tutor feature with real tokens; no network was hit (fake provider).
    $row = AiUsage::query()->where('feature', 'tutor')->firstOrFail();
    expect($row->prompt_key)->toBe('tutor.answer')
        ->and($row->user_id)->toBe($learner->id)
        ->and($row->input_tokens)->toBeGreaterThan(0)
        ->and($row->request_id)->toBe($data['request_id']);
});

it('blocks a learner who is not enrolled in the course (403)', function () {
    [$course] = tutorLessonCourse('cell mitosis divides into two daughter cells');
    Artisan::call('search:backfill');

    $stranger = User::factory()->create(); // enrolled in nothing

    $this->actingAs($stranger, 'sanctum')
        ->postJson('/api/v1/ai/tutor', [
            'course_id' => $course->public_id,
            'question' => 'cell mitosis divides into two daughter cells',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'NOT_ENROLLED');

    expect(AiUsage::query()->count())->toBe(0);
});

it('never grounds an answer in another course or in an unpublished lesson (retrieval scope)', function () {
    [$courseA] = tutorLessonCourse('alpha orchard apples ripen in autumn sunlight');
    [$courseB] = tutorLessonCourse('beta banana groves flourish in tropical climates');

    // An UNPUBLISHED lesson in course A: never indexed, never retrievable.
    $draftSection = Section::factory()->published()->create(['course_id' => $courseA->id]);
    $draftLesson = Lesson::factory()->create(['section_id' => $draftSection->id, 'title' => 'Draft']); // unpublished
    Block::factory()->withContent(['en' => ['text' => 'gamma cherry vault hidden secret archive']])
        ->create(['lesson_id' => $draftLesson->id]);

    $learner = tutorEnrol($courseA);
    Artisan::call('search:backfill');

    // Course B IS indexed — proving the exclusion below is scoping, not missing data.
    expect(ContentEmbedding::query()->where('course_id', $courseB->id)->count())->toBeGreaterThan(0);

    // Asking course A's tutor about course B's content returns nothing from B.
    $crossCourse = $this->actingAs($learner, 'sanctum')
        ->postJson('/api/v1/ai/tutor', [
            'course_id' => $courseA->public_id,
            'question' => 'beta banana groves flourish in tropical climates',
        ])->assertOk()->json('data');

    expect($crossCourse['citations'])->toBe([]);

    // The unpublished lesson in course A is never surfaced either.
    $unpublished = $this->actingAs($learner, 'sanctum')
        ->postJson('/api/v1/ai/tutor', [
            'course_id' => $courseA->public_id,
            'question' => 'gamma cherry vault hidden secret archive',
        ])->assertOk()->json('data');

    expect($unpublished['citations'])->toBe([]);
});

it('refuses to reveal quiz answers / an answer key without calling the provider', function () {
    [$course] = tutorLessonCourse('newton three laws of motion govern classical mechanics');
    $learner = tutorEnrol($course);
    Artisan::call('search:backfill');

    $data = $this->actingAs($learner, 'sanctum')
        ->postJson('/api/v1/ai/tutor', [
            'course_id' => $course->public_id,
            'question' => 'Just give me the quiz answers and the answer key please',
        ])
        ->assertOk()
        ->json('data');

    expect($data['refused'])->toBeTrue()
        ->and($data['citations'])->toBe([]);

    // A refusal short-circuits before any provider call — nothing metered, no key leaked.
    expect(AiUsage::query()->count())->toBe(0);
});

it('returns a clear disabled response when the tutor feature is governance-disabled', function () {
    config(['ai.features.tutor' => false]);

    [$course] = tutorLessonCourse('supply and demand set the market clearing price');
    $learner = tutorEnrol($course);
    Artisan::call('search:backfill');

    $this->actingAs($learner, 'sanctum')
        ->postJson('/api/v1/ai/tutor', [
            'course_id' => $course->public_id,
            'question' => 'supply and demand set the market clearing price',
        ])
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'AI_FEATURE_DISABLED')
        ->assertJsonPath('error.details.reason', 'feature');

    expect(AiUsage::query()->count())->toBe(0);
});

it('blocks the call when the token quota is exhausted, before any provider spend', function () {
    config(['ai.limits.max_tokens_per_request' => 1]);

    [$course] = tutorLessonCourse('photosynthesis converts light energy into chemical energy');
    $learner = tutorEnrol($course);
    Artisan::call('search:backfill');

    $this->actingAs($learner, 'sanctum')
        ->postJson('/api/v1/ai/tutor', [
            'course_id' => $course->public_id,
            'question' => 'photosynthesis converts light energy into chemical energy',
        ])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'AI_QUOTA_EXCEEDED');

    // Quota trips BEFORE the provider, so no usage row is written.
    expect(AiUsage::query()->count())->toBe(0);
});
