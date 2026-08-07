<?php

use App\Domains\Assessment\Actions\Assessment\DuplicateAssessmentAction;
use App\Domains\Assessment\Actions\Question\DuplicateQuestionAction;
use App\Domains\Assessment\Enums\AssessmentStatus;
use App\Domains\Assessment\Enums\QuestionType;
use App\Domains\Assessment\Models\Assessment;
use App\Domains\Assessment\Models\AssessmentAttempt;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Audit\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
});

/** Build a two-question assessment (one choice, one text-matched) with bilingual content and config. */
function seededAssessment(): Assessment
{
    $assessment = Assessment::factory()->published()->create([
        'title_i18n' => ['en' => 'Module Quiz', 'ar' => 'اختبار الوحدة'],
        'description_i18n' => ['en' => 'Covers the basics.', 'ar' => 'يغطي الأساسيات.'],
        'passing_score' => 70,
        'negative_marking' => true,
    ]);

    $choice = $assessment->questions()->create([
        'type' => QuestionType::SingleChoice,
        'prompt_i18n' => ['en' => 'Capital of France?', 'ar' => 'ما هي عاصمة فرنسا؟'],
        'explanation_i18n' => ['en' => 'It is Paris.', 'ar' => 'إنها باريس.'],
        'points' => 2,
        'position' => 1,
    ]);
    $choice->options()->create(['label_i18n' => ['en' => 'Paris', 'ar' => 'باريس'], 'is_correct' => true, 'position' => 0]);
    $choice->options()->create(['label_i18n' => ['en' => 'Berlin', 'ar' => 'برلين'], 'is_correct' => false, 'position' => 1]);

    $text = $assessment->questions()->create([
        'type' => QuestionType::ShortAnswer,
        'prompt_i18n' => ['en' => 'Largest planet?'],
        'config' => ['case_sensitive' => true, 'normalize_arabic' => false],
        'points' => 3,
        'position' => 2,
    ]);
    $text->options()->create(['value' => 'Jupiter', 'is_correct' => true, 'position' => 0]);

    return $assessment->fresh();
}

it('deep-duplicates an assessment into a fresh draft copy with fresh ids', function () {
    $source = seededAssessment();
    $actor = User::factory()->create();

    $copy = app(DuplicateAssessmentAction::class)->execute($source, $actor->id);

    expect($copy->id)->not->toBe($source->id)
        ->and($copy->public_id)->not->toBe($source->public_id)
        ->and($copy->status)->toBe(AssessmentStatus::Draft)     // forced Draft, never born published
        ->and($copy->version)->toBe(1)
        ->and($copy->parent_assessment_id)->toBeNull()
        ->and($copy->created_by)->toBe($actor->id)
        ->and($copy->course_id)->toBe($source->course_id)
        ->and($copy->negative_marking)->toBeTrue()
        ->and((int) $copy->passing_score)->toBe(70)
        ->and($copy->questions()->count())->toBe(2);

    // Fresh question and option ids — nothing is shared with the source graph.
    $sourceQuestionIds = $source->questions()->pluck('id')->all();
    $copyQuestionIds = $copy->questions()->pluck('id')->all();
    expect(array_intersect($sourceQuestionIds, $copyQuestionIds))->toBe([]);
});

it('preserves bilingual translation maps and per-type config on duplicate', function () {
    $source = seededAssessment();

    $copy = app(DuplicateAssessmentAction::class)->execute($source, null);

    expect($copy->title_i18n)->toEqual($source->title_i18n)
        ->and($copy->description_i18n)->toEqual($source->description_i18n)
        ->and($copy->title)->toBe('Module Quiz');            // legacy scalar re-synced from EN

    $copyChoice = $copy->questions()->orderBy('position')->first();
    $copyText = $copy->questions()->orderBy('position')->skip(1)->first();

    expect($copyChoice->prompt_i18n)->toEqual(['en' => 'Capital of France?', 'ar' => 'ما هي عاصمة فرنسا؟'])
        ->and($copyChoice->explanation_i18n['ar'])->toBe('إنها باريس.')
        ->and($copyChoice->options()->where('is_correct', true)->count())->toBe(1)
        ->and($copyChoice->options()->orderBy('position')->first()->label_i18n['ar'])->toBe('باريس')
        // jsonb config carried by value.
        ->and($copyText->config)->toEqual(['case_sensitive' => true, 'normalize_arabic' => false])
        ->and($copyText->options()->first()->value)->toBe('Jupiter');
});

it('never copies attempts and leaves the source history intact', function () {
    $source = seededAssessment();
    $learner = User::factory()->create();
    AssessmentAttempt::factory()->create(['assessment_id' => $source->id, 'user_id' => $learner->id]);

    $copy = app(DuplicateAssessmentAction::class)->execute($source, null);

    expect($copy->attempts()->count())->toBe(0)
        ->and(AssessmentAttempt::where('assessment_id', $source->id)->count())->toBe(1);
});

it('writes an audit entry for the duplication', function () {
    $source = seededAssessment();
    $actor = User::factory()->create();

    $copy = app(DuplicateAssessmentAction::class)->execute($source, $actor->id);

    $entry = AuditLog::query()->where('action', 'assessment.duplicated')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->actor_id)->toBe($actor->id)
        ->and((int) $entry->subject_id)->toBe((int) $copy->id)
        ->and($entry->context['source_id'])->toBe($source->id);
});

it('duplicates a single question appended to the end of the assessment', function () {
    $source = seededAssessment();
    $original = $source->questions()->orderBy('position')->first();

    $copy = app(DuplicateQuestionAction::class)->execute($original);

    expect($copy->id)->not->toBe($original->id)
        ->and($copy->assessment_id)->toBe($source->id)
        ->and($copy->position)->toBe(3)                       // appended after positions 1 and 2
        ->and($copy->prompt_i18n)->toEqual($original->prompt_i18n)
        ->and($copy->options()->count())->toBe($original->options()->count())
        ->and($copy->options()->where('is_correct', true)->count())->toBe(1)
        ->and($source->questions()->count())->toBe(3);
});

it('denies duplication authorization to a non-owning instructor', function () {
    $owner = User::factory()->create();
    $owner->assignRole(SpatieRole::findByName('instructor', 'web'));
    $intruder = User::factory()->create();
    $intruder->assignRole(SpatieRole::findByName('instructor', 'web'));

    $course = Course::factory()->create(['status' => CourseStatus::Draft]);
    $course->syncTrainers([$owner->id]);
    $assessment = Assessment::factory()->create(['course_id' => $course->id]);

    // The Filament duplicate action is gated by the `update` ability (AssessmentPolicy course ownership).
    expect($owner->can('update', $assessment))->toBeTrue()
        ->and($intruder->can('update', $assessment))->toBeFalse();
});
