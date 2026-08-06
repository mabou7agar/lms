<?php

use App\Domains\Catalog\Models\Course;
use App\Domains\Certification\Models\CertificateTemplate;
use App\Platform\Shared\I18n\UnsupportedLocaleException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Model-level translation storage contract (HasTranslations + TranslationResolver): per-locale
 * writes, the supported-locale allowlist, the requested -> fallback -> legacy resolution chain, the
 * saving hook that keeps the legacy scalar synced to the `en` value, and HTML round-tripping.
 */
beforeEach(function () {
    config([
        'shared.locales' => ['en', 'ar'],
        'shared.fallback_locale' => 'en',
        'shared.default_locale' => 'en',
    ]);
});

it('stores a translation per locale without dropping the others', function () {
    $course = new Course;

    $course->setTranslation('title_i18n', 'en', 'English Title')
        ->setTranslation('title_i18n', 'ar', 'العنوان بالعربية');

    expect($course->title_i18n)->toBe(['en' => 'English Title', 'ar' => 'العنوان بالعربية']);

    // A second write to one locale must not wipe the other.
    $course->setTranslation('title_i18n', 'en', 'Updated English');

    expect($course->title_i18n)->toBe(['en' => 'Updated English', 'ar' => 'العنوان بالعربية']);
});

it('throws UnsupportedLocaleException when writing an unsupported locale', function () {
    $course = new Course;

    expect(fn () => $course->setTranslation('title_i18n', 'fr', 'Bonjour'))
        ->toThrow(UnsupportedLocaleException::class);
});

it('resolves the requested locale first when it is present', function () {
    $course = new Course;
    $course->setTranslation('title_i18n', 'en', 'English Title')
        ->setTranslation('title_i18n', 'ar', 'العنوان بالعربية');

    app()->setLocale('ar');

    expect($course->localized('title'))->toBe('العنوان بالعربية');
});

it('falls back to the application fallback locale when the requested locale is absent', function () {
    $course = new Course;
    // Only en translated; requesting ar must fall back to en (not an empty string).
    $course->setTranslation('title_i18n', 'en', 'English Title');

    app()->setLocale('ar');

    expect($course->localized('title'))->toBe('English Title');
});

it('falls back to the legacy scalar when the i18n map is empty', function () {
    $course = new Course;
    $course->title = 'Legacy Scalar Title';
    $course->title_i18n = null;

    app()->setLocale('ar');

    expect($course->localized('title'))->toBe('Legacy Scalar Title');
});

it('keeps the legacy scalar synced to the en value on save', function () {
    $course = Course::factory()->create([
        'title' => 'Ignored Factory Title',
        'title_i18n' => ['en' => 'Hook English', 'ar' => 'Hook Arabic'],
    ]);

    // The saving hook overwrites the scalar with the default-locale (en) value.
    expect($course->refresh()->title)->toBe('Hook English');
});

it('round-trips an HTML translation through the html_i18n column', function () {
    // Attribute-free safe markup: the HtmlSanitizer preserves these tags verbatim (it strips
    // attributes such as class/dir), so the round-trip is exact. jsonb does not preserve key order,
    // so compare values with toEqual rather than identity.
    $html = [
        'en' => '<div><h1>Certificate &amp; Award</h1><p>Well done!</p></div>',
        'ar' => '<div><h1>شهادة تقدير</h1><p>أحسنت!</p></div>',
    ];

    $template = CertificateTemplate::factory()->create(['html_i18n' => $html]);
    $fresh = $template->refresh();

    expect($fresh->html_i18n)->toEqual($html);

    app()->setLocale('ar');
    expect($fresh->localized('html'))->toBe($html['ar']);

    app()->setLocale('en');
    expect($fresh->localized('html'))->toBe($html['en']);
});
