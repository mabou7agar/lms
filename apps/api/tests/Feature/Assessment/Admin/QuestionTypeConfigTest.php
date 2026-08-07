<?php

use App\Domains\Assessment\Enums\QuestionType;
use App\Domains\Assessment\Grading\Graders\MultipleChoiceGrader;
use App\Domains\Assessment\Grading\Graders\ShortAnswerGrader;
use App\Domains\Assessment\Models\Assessment;
use App\Domains\Assessment\Models\AssessmentAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists and honours the case_sensitive config key a text question authors', function () {
    $assessment = Assessment::factory()->create();
    $question = $assessment->questions()->create([
        'type' => QuestionType::ShortAnswer,
        'prompt_i18n' => ['en' => 'Capital of France?'],
        'config' => ['case_sensitive' => true, 'normalize_arabic' => false],
        'points' => 1,
        'position' => 1,
    ]);
    $question->options()->create(['value' => 'Paris', 'is_correct' => true, 'position' => 0]);
    $question->load('options');

    expect($question->fresh()->config)->toEqual(['case_sensitive' => true, 'normalize_arabic' => false])
        ->and($question->setting('case_sensitive'))->toBeTrue();

    $grader = app(ShortAnswerGrader::class);

    // case_sensitive = true → a lowercase answer is wrong; the exposed key genuinely drives grading.
    $wrong = new AssessmentAnswer(['response' => ['text' => 'paris']]);
    $right = new AssessmentAnswer(['response' => ['text' => 'Paris']]);

    expect($grader->grade($question, $wrong)->isCorrect)->toBeFalse()
        ->and($grader->grade($question, $right)->isCorrect)->toBeTrue();
});

it('persists and honours the partial_credit config key for multiple choice', function () {
    $assessment = Assessment::factory()->create();
    $question = $assessment->questions()->create([
        'type' => QuestionType::MultipleChoice,
        'prompt_i18n' => ['en' => 'Pick the gases'],
        'config' => ['partial_credit' => true],
        'points' => 2,
        'position' => 1,
    ]);
    $a = $question->options()->create(['label_i18n' => ['en' => 'Oxygen'], 'is_correct' => true, 'position' => 0]);
    $question->options()->create(['label_i18n' => ['en' => 'Nitrogen'], 'is_correct' => true, 'position' => 1]);
    $question->options()->create(['label_i18n' => ['en' => 'Iron'], 'is_correct' => false, 'position' => 2]);
    $question->load('options');

    $grader = app(MultipleChoiceGrader::class);

    // One of two correct selected → 0.5 under partial credit (the exposed key is grader-backed).
    $answer = new AssessmentAnswer(['response' => ['option_ids' => [$a->public_id]]]);

    expect($question->fresh()->config)->toEqual(['partial_credit' => true])
        ->and($grader->grade($question, $answer)->ratio)->toBe(0.5);
});

it('scopes config keys to the types whose graders read them', function () {
    // The form exposes case_sensitive / normalize_arabic only for text-matching types...
    expect(QuestionType::ShortAnswer->usesTextMatching())->toBeTrue()
        ->and(QuestionType::FillInBlank->usesTextMatching())->toBeTrue()
        ->and(QuestionType::SingleChoice->usesTextMatching())->toBeFalse()
        ->and(QuestionType::TrueFalse->usesTextMatching())->toBeFalse()
        // ...and partial_credit only where a grader honours it (multiple_choice, fill_in_blank).
        ->and(QuestionType::MultipleChoice->allowsMultipleCorrect())->toBeTrue()
        ->and(QuestionType::SingleChoice->allowsMultipleCorrect())->toBeFalse();
});
