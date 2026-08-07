<?php

namespace App\Domains\Assessment\Filament\Support;

use App\Domains\Assessment\Enums\QuestionType;
use App\Domains\Assessment\Models\Assessment;
use App\Domains\Assessment\Models\AssessmentQuestion;
use App\Domains\Assessment\Models\QuestionOption;
use App\Platform\Shared\Helpers\LocaleHelper;
use Illuminate\Support\HtmlString;

/**
 * Renders a READ-ONLY, learner-style preview of an assessment for the admin panel. It resolves every
 * field through the same TranslationResolver path the learner endpoints use (Model::localized), and
 * the prompt / explanation / hint HTML it emits was already sanitized on write by HasTranslations —
 * so nothing is sanitized, mutated or persisted here. Building the preview creates NO attempt, writes
 * NO answer and triggers NO grading: it is a pure projection of already-stored rows into markup.
 *
 * Every runtime-supported question type is rendered in the learner's own shape (choices as disabled
 * radios / checkboxes, text answers as a disabled input, fill-in-the-blank as one input per blank),
 * once per supported locale with the correct text direction (EN ltr, AR rtl). The answer key is
 * surfaced only as a subtle author aid — this surface is admin-only and gated by AssessmentPolicy.
 */
class AssessmentPreviewRenderer
{
    public function render(Assessment $assessment): HtmlString
    {
        $assessment->loadMissing('questions.options');

        $sections = [];
        foreach (LocaleHelper::supported() as $locale) {
            $sections[] = $this->localeSection($assessment, (string) $locale);
        }

        return new HtmlString('<div style="display:flex;flex-direction:column;gap:2rem;">'.implode('', $sections).'</div>');
    }

    private function localeSection(Assessment $assessment, string $locale): string
    {
        $dir = LocaleHelper::direction($locale);
        $align = $dir === 'rtl' ? 'right' : 'left';

        $title = $this->text($assessment->localized('title', $locale)) ?: $this->text($assessment->getAttribute('title'));
        $description = $this->html($assessment->localized('description', $locale));

        $questions = $assessment->questions
            ->map(fn (AssessmentQuestion $question, int $index): string => $this->question($question, $locale, $index + 1))
            ->implode('');

        if ($questions === '') {
            $questions = '<p style="color:#6b7280;font-style:italic;">This assessment has no questions yet.</p>';
        }

        return <<<HTML
            <section dir="{$dir}" style="text-align:{$align};border:1px solid #e5e7eb;border-radius:0.5rem;padding:1.25rem;">
                <p style="text-transform:uppercase;letter-spacing:0.05em;font-size:0.7rem;color:#6b7280;margin:0 0 0.5rem;">{$this->localeLabel($locale)}</p>
                <h2 style="font-size:1.25rem;font-weight:700;margin:0 0 0.5rem;">{$title}</h2>
                <div style="color:#4b5563;margin:0 0 1rem;">{$description}</div>
                <div style="display:flex;flex-direction:column;gap:1rem;">{$questions}</div>
            </section>
            HTML;
    }

    private function question(AssessmentQuestion $question, string $locale, int $number): string
    {
        $prompt = $this->html($question->localized('prompt', $locale));
        $points = rtrim(rtrim(number_format((float) $question->points, 2, '.', ''), '0'), '.');
        $typeLabel = $this->text($question->type->label());
        $body = $this->answerArea($question, $locale);

        $hintHtml = '';
        $hint = $this->html($question->localized('hint', $locale));
        if ($hint !== '') {
            $hintHtml = '<div style="margin-top:0.5rem;font-size:0.85rem;color:#6b7280;"><strong>Hint:</strong> '.$hint.'</div>';
        }

        $explanationHtml = '';
        $explanation = $this->html($question->localized('explanation', $locale));
        if ($explanation !== '') {
            $explanationHtml = '<div style="margin-top:0.5rem;font-size:0.85rem;color:#6b7280;"><strong>Explanation:</strong> '.$explanation.'</div>';
        }

        return <<<HTML
            <div style="border-top:1px solid #f3f4f6;padding-top:0.75rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;align-items:baseline;">
                    <div style="font-weight:600;">{$number}. <span style="font-weight:400;">{$prompt}</span></div>
                    <div style="white-space:nowrap;font-size:0.75rem;color:#6b7280;">{$typeLabel} · {$points} pts</div>
                </div>
                <div style="margin-top:0.5rem;">{$body}</div>
                {$hintHtml}
                {$explanationHtml}
            </div>
            HTML;
    }

    private function answerArea(AssessmentQuestion $question, string $locale): string
    {
        if ($question->type->usesOptions()) {
            return $this->choiceOptions($question, $locale);
        }

        if ($question->type->usesTextMatching()) {
            return $question->type === QuestionType::FillInBlank
                ? $this->blankInputs($question, $locale)
                : $this->textInput($question);
        }

        return '';
    }

    private function choiceOptions(AssessmentQuestion $question, string $locale): string
    {
        $control = $question->type->allowsMultipleCorrect() ? 'checkbox' : 'radio';

        $rows = $question->options->map(function (QuestionOption $option) use ($locale, $control): string {
            $label = $this->text($option->localized('label', $locale)) ?: $this->text($option->getAttribute('label'));
            $marker = $option->is_correct
                ? '<span style="color:#059669;font-size:0.8rem;margin-inline-start:0.5rem;">✓ correct</span>'
                : '';

            return '<label style="display:flex;align-items:center;gap:0.5rem;padding:0.25rem 0;">'
                .'<input type="'.$control.'" disabled>'
                .'<span>'.$label.'</span>'.$marker.'</label>';
        })->implode('');

        return '<div>'.$rows.'</div>';
    }

    private function textInput(AssessmentQuestion $question): string
    {
        return '<input type="text" disabled placeholder="Learner types the answer"'
            .' style="width:100%;max-width:24rem;padding:0.375rem 0.5rem;border:1px solid #d1d5db;border-radius:0.375rem;">'
            .$this->acceptedAnswers($question, 0);
    }

    private function blankInputs(AssessmentQuestion $question, string $locale): string
    {
        $groups = $question->options
            ->pluck('group_index')
            ->unique()
            ->sort()
            ->values();

        $rows = $groups->map(function (int $group) use ($question): string {
            return '<div style="display:flex;align-items:center;gap:0.5rem;padding:0.25rem 0;">'
                .'<span style="font-size:0.85rem;color:#6b7280;">Blank '.($group + 1).':</span>'
                .'<input type="text" disabled'
                .' style="flex:1;max-width:18rem;padding:0.375rem 0.5rem;border:1px solid #d1d5db;border-radius:0.375rem;">'
                .$this->acceptedAnswers($question, $group)
                .'</div>';
        })->implode('');

        return '<div>'.$rows.'</div>';
    }

    /** The author-facing accepted-answer list for a text-matched blank (never shown to a learner). */
    private function acceptedAnswers(AssessmentQuestion $question, int $group): string
    {
        $accepted = $question->options
            ->where('is_correct', true)
            ->where('group_index', $group)
            ->map(fn (QuestionOption $option): string => $this->text($option->comparableValue()))
            ->filter(fn (string $value): bool => $value !== '')
            ->implode(', ');

        return $accepted === ''
            ? ''
            : '<span style="font-size:0.8rem;color:#059669;margin-inline-start:0.5rem;">accepts: '.$accepted.'</span>';
    }

    /** Escape plain-text values for safe interpolation. */
    private function text(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? e((string) $value) : '';
    }

    /** Emit already-sanitized rich-text HTML as-is (sanitized on write by HasTranslations). */
    private function html(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function localeLabel(string $locale): string
    {
        return e(mb_strtoupper($locale)).($locale === 'ar' ? ' · عربي' : '');
    }
}
