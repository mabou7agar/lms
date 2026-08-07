<?php

use App\Domains\Assessment\Enums\QuestionType;
use App\Domains\Assessment\Filament\Support\AssessmentPreviewRenderer;
use App\Domains\Assessment\Models\Assessment;
use App\Domains\Assessment\Models\AssessmentAnswer;
use App\Domains\Assessment\Models\AssessmentAttempt;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\HtmlString;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
});

function previewableAssessment(): Assessment
{
    $assessment = Assessment::factory()->create([
        'title_i18n' => ['en' => 'Science Quiz', 'ar' => 'اختبار العلوم'],
    ]);

    $choice = $assessment->questions()->create([
        'type' => QuestionType::MultipleChoice,
        'prompt_i18n' => ['en' => 'Pick the gases', 'ar' => 'اختر الغازات'],
        'points' => 2,
        'position' => 1,
    ]);
    $choice->options()->create(['label_i18n' => ['en' => 'Oxygen', 'ar' => 'أكسجين'], 'is_correct' => true, 'position' => 0]);
    $choice->options()->create(['label_i18n' => ['en' => 'Iron', 'ar' => 'حديد'], 'is_correct' => false, 'position' => 1]);

    $text = $assessment->questions()->create([
        'type' => QuestionType::ShortAnswer,
        'prompt_i18n' => ['en' => 'Largest planet?', 'ar' => 'أكبر كوكب؟'],
        'points' => 1,
        'position' => 2,
    ]);
    $text->options()->create(['value' => 'Jupiter', 'is_correct' => true, 'position' => 0]);

    return $assessment->fresh();
}

it('renders a bilingual read-only preview covering every runtime question type', function () {
    $assessment = previewableAssessment();

    $html = app(AssessmentPreviewRenderer::class)->render($assessment);

    expect($html)->toBeInstanceOf(HtmlString::class);

    $markup = (string) $html;

    expect($markup)->toContain('Science Quiz')          // EN title
        ->and($markup)->toContain('اختبار العلوم')       // AR title
        ->and($markup)->toContain('dir="rtl"')          // Arabic section is right-to-left
        ->and($markup)->toContain('Pick the gases')     // choice prompt (EN)
        ->and($markup)->toContain('اختر الغازات')        // choice prompt (AR)
        ->and($markup)->toContain('Largest planet?')    // text prompt
        ->and($markup)->toContain('type="checkbox"')    // multiple-choice control
        ->and($markup)->toContain('type="text"')        // short-answer control
        ->and($markup)->toContain('disabled');          // read-only inputs
});

it('creates no attempt, answer or extra question when previewing', function () {
    $assessment = previewableAssessment();
    $learner = User::factory()->create();
    AssessmentAttempt::factory()->create(['assessment_id' => $assessment->id, 'user_id' => $learner->id]);

    $attemptsBefore = AssessmentAttempt::count();
    $answersBefore = AssessmentAnswer::count();
    $questionsBefore = $assessment->questions()->count();

    app(AssessmentPreviewRenderer::class)->render($assessment);

    expect(AssessmentAttempt::count())->toBe($attemptsBefore)
        ->and(AssessmentAnswer::count())->toBe($answersBefore)
        ->and($assessment->questions()->count())->toBe($questionsBefore);
});
