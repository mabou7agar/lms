<?php

use App\Domains\Assessment\Models\AssessmentQuestion;
use App\Domains\Certification\Models\CertificateTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Per-locale HTML sanitization for the newly-localized rich-HTML translatable maps. The models run
 * every locale value of their declared HTML fields through the shared HtmlSanitizer on save (the
 * same defense-in-depth point exercised by tests/Unit/Shared/HtmlSanitizerTest.php), so dangerous
 * markup authored via Filament in EITHER locale is stripped. Plain-text translatable fields
 * (e.g. name_i18n) are deliberately left untouched.
 */
beforeEach(function () {
    config([
        'shared.locales' => ['en', 'ar'],
        'shared.fallback_locale' => 'en',
        'shared.default_locale' => 'en',
    ]);
});

/** Dangerous-but-recoverable HTML: a script, a javascript: URL, an onerror handler, safe markup. */
function dirtyHtml(string $lang, string $safeWord): string
{
    return "<p>{$lang} <strong>{$safeWord}</strong></p>"
        .'<script>alert(1)</script>'
        .'<a href="javascript:alert(1)" onclick="window.pwn=1">bad link</a>'
        .'<a href="https://example.com" title="ok">good link</a>'
        .'<img src="https://cdn.example.com/a.png" alt="ok" onerror="pwn()">';
}

function expectSanitized(string $clean, string $safeWord): void
{
    expect($clean)
        ->toContain("<strong>{$safeWord}</strong>")
        ->toContain('https://example.com')
        ->toContain('<img src="https://cdn.example.com/a.png"')
        ->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('onerror')
        ->not->toContain('javascript:');
}

it('strips dangerous HTML from both locales of CertificateTemplate.html_i18n while keeping safe markup', function () {
    $template = CertificateTemplate::factory()->create([
        'html_i18n' => [
            'en' => dirtyHtml('Certificate', 'Award'),
            'ar' => dirtyHtml('شهادة', 'تقدير'),
        ],
    ]);

    $map = $template->refresh()->html_i18n;

    expectSanitized($map['en'], 'Award');
    expectSanitized($map['ar'], 'تقدير');
});

it('strips dangerous HTML from both locales of AssessmentQuestion.prompt_i18n while keeping safe markup', function () {
    $question = AssessmentQuestion::factory()->create([
        'prompt_i18n' => [
            'en' => dirtyHtml('Question', 'Prompt'),
            'ar' => dirtyHtml('سؤال', 'المطلوب'),
        ],
    ]);

    $map = $question->refresh()->prompt_i18n;

    expectSanitized($map['en'], 'Prompt');
    expectSanitized($map['ar'], 'المطلوب');
});

it('also sanitizes the explanation_i18n HTML map on AssessmentQuestion', function () {
    $question = AssessmentQuestion::factory()->create([
        'explanation_i18n' => [
            'en' => dirtyHtml('Because', 'Reason'),
            'ar' => dirtyHtml('لأن', 'السبب'),
        ],
    ]);

    $map = $question->refresh()->explanation_i18n;

    expectSanitized($map['en'], 'Reason');
    expectSanitized($map['ar'], 'السبب');
});

it('leaves a plain-text translatable field (name_i18n) untouched by the HTML sanitizer', function () {
    // name_i18n is plain text — running the HTML sanitizer over it could corrupt it, so it must NOT
    // be touched. A value with an ampersand and angle brackets round-trips verbatim.
    $names = ['en' => 'Design & Build <2026>', 'ar' => 'التصميم والبناء <2026>'];

    $template = CertificateTemplate::factory()->create(['name_i18n' => $names]);

    // jsonb does not preserve key order; content must be identical (name is never HTML-sanitized).
    expect($template->refresh()->name_i18n)->toEqual($names);
});
