<?php

use App\Contexts\Commerce\Http\Resources\ProductResource;
use App\Contexts\Commerce\Models\Product;
use App\Domains\Authoring\Http\Resources\LessonResource;
use App\Domains\Authoring\Http\Resources\SectionResource;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Domains\Certification\Models\CertificateTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Domain resources emit a localized STRING for each translatable field — never a {en,ar} map. Pins
 * the string-out contract (requested locale, ar fallback to en, and no map leak) across Curriculum
 * (Section / Lesson), Commerce (Product, via the real public storefront endpoint) and Certification
 * (template HTML, at the localized() level as it has no public string-out endpoint).
 */
beforeEach(function () {
    config([
        'shared.locales' => ['en', 'ar'],
        'shared.fallback_locale' => 'en',
        'shared.default_locale' => 'en',
    ]);
});

it('emits a localized string for a Section title and summary in en, ar, and fallback', function () {
    $section = Section::factory()->create([
        'title_i18n' => ['en' => 'Getting Started', 'ar' => 'البداية'],
        'summary_i18n' => ['en' => 'The basics'], // ar absent on purpose to exercise fallback
    ]);

    app()->setLocale('ar');
    expect($section->localized('title'))->toBe('البداية')->toBeString()
        ->and($section->localized('summary'))->toBe('The basics'); // fell back to en

    app()->setLocale('en');
    expect($section->localized('title'))->toBe('Getting Started');

    $out = (new SectionResource($section))->resolve();
    expect($out['title'])->toBeString()->and(is_array($out['title']))->toBeFalse();
});

it('emits a localized string for a Lesson title in en, ar, and fallback', function () {
    $arLesson = Lesson::factory()->create(['title_i18n' => ['en' => 'Intro', 'ar' => 'مقدمة']]);
    $enOnly = Lesson::factory()->create(['title_i18n' => ['en' => 'English Only']]);

    app()->setLocale('ar');
    expect($arLesson->localized('title'))->toBe('مقدمة')->toBeString()
        ->and($enOnly->localized('title'))->toBe('English Only'); // fallback to en

    $out = (new LessonResource($arLesson))->resolve();
    expect($out['title'])->toBeString()->and(is_array($out['title']))->toBeFalse();
});

it('returns a localized product title string from the public storefront endpoint (en/ar/fallback)', function () {
    $product = Product::factory()->create([
        'title_i18n' => ['en' => 'Pro Plan', 'ar' => 'الخطة الاحترافية'],
        'description_i18n' => ['en' => 'Everything included'],
    ]);

    $ar = $this->getJson('/api/v1/products?locale=ar')->assertOk()->json('data.0');
    expect($ar['title'])->toBe('الخطة الاحترافية')->toBeString()
        ->and($ar['description'])->toBe('Everything included'); // ar absent -> fallback en

    $en = $this->getJson('/api/v1/products?locale=en')->assertOk()->json('data.0');
    expect($en['title'])->toBe('Pro Plan')->toBeString();

    // Guard against a shape regression that would surface the product under a different index.
    expect($ar['id'])->toBe($product->public_id);
});

it('resolves a localized certificate template html string in en, ar, and fallback', function () {
    $withBoth = CertificateTemplate::factory()->create([
        'html_i18n' => ['en' => '<p>Certificate</p>', 'ar' => '<p>شهادة</p>'],
    ]);
    $enOnly = CertificateTemplate::factory()->create([
        'key' => 'en-only',
        'html_i18n' => ['en' => '<p>English Certificate</p>'],
    ]);

    app()->setLocale('ar');
    expect($withBoth->localized('html'))->toBe('<p>شهادة</p>')->toBeString()
        ->and($enOnly->localized('html'))->toBe('<p>English Certificate</p>'); // fallback to en

    app()->setLocale('en');
    expect($withBoth->localized('html'))->toBe('<p>Certificate</p>')->toBeString();
});

it('never leaks a {en,ar} map through the Section or Product resource boundary', function () {
    $section = Section::factory()->create(['title_i18n' => ['en' => 'A', 'ar' => 'أ']]);
    $product = Product::factory()->create(['title_i18n' => ['en' => 'B', 'ar' => 'ب']]);

    app()->setLocale('en');
    $sectionOut = (new SectionResource($section))->resolve();
    $productOut = (new ProductResource($product))->resolve();

    expect(is_array($sectionOut['title']))->toBeFalse()
        ->and($sectionOut['title'])->toBeString()
        ->and(is_array($productOut['title']))->toBeFalse()
        ->and($productOut['title'])->toBeString()
        ->and(is_array($productOut['description']))->toBeFalse();
});
