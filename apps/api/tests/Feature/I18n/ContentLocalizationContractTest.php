<?php

use App\Domains\Catalog\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * HTTP localization contract for the public Course detail endpoint
 * (GET /api/v1/courses/{public_id}; requires published + public visibility).
 *
 * The SetLocale middleware on the `api` group resolves the request locale (?locale / Accept-Language)
 * and CourseResource emits localized() STRINGS — never a {en,ar} map. These tests pin that contract
 * across an AR request, an EN request, an EN-only fallback, and a legacy scalar-only row.
 */
beforeEach(function () {
    config([
        'shared.locales' => ['en', 'ar'],
        'shared.fallback_locale' => 'en',
        'shared.default_locale' => 'en',
    ]);
});

function publishedCourseWithTitles(array $titleI18n): Course
{
    return Course::factory()->published()->create([
        'title_i18n' => $titleI18n,
        'subtitle_i18n' => ['en' => 'Learn the fundamentals', 'ar' => 'تعلّم الأساسيات'],
    ]);
}

it('returns the Arabic title when the request resolves to ar via ?locale', function () {
    $course = publishedCourseWithTitles(['en' => 'Introduction to Programming', 'ar' => 'مقدمة في البرمجة']);

    $data = $this->getJson('/api/v1/courses/'.$course->public_id.'?locale=ar')
        ->assertOk()
        ->json('data');

    expect($data['title'])->toBe('مقدمة في البرمجة')
        ->and($data['subtitle'])->toBe('تعلّم الأساسيات')
        ->and($data['title'])->toBeString();
});

it('returns the Arabic title when the request resolves to ar via Accept-Language', function () {
    $course = publishedCourseWithTitles(['en' => 'Introduction to Programming', 'ar' => 'مقدمة في البرمجة']);

    $title = $this->getJson('/api/v1/courses/'.$course->public_id, ['Accept-Language' => 'ar'])
        ->assertOk()
        ->json('data.title');

    expect($title)->toBe('مقدمة في البرمجة');
});

it('returns the English title when the request resolves to en', function () {
    $course = publishedCourseWithTitles(['en' => 'Introduction to Programming', 'ar' => 'مقدمة في البرمجة']);

    $title = $this->getJson('/api/v1/courses/'.$course->public_id.'?locale=en')
        ->assertOk()
        ->json('data.title');

    expect($title)->toBe('Introduction to Programming');
});

it('falls back to the English value for an ar request when only en is translated', function () {
    // Only en present in the map; an ar request must fall back to en, not return an empty string.
    $course = Course::factory()->published()->create([
        'title_i18n' => ['en' => 'English Only Course'],
        'subtitle_i18n' => ['en' => 'Only English subtitle'],
    ]);

    $data = $this->getJson('/api/v1/courses/'.$course->public_id.'?locale=ar')
        ->assertOk()
        ->json('data');

    expect($data['title'])->toBe('English Only Course')
        ->and($data['subtitle'])->toBe('Only English subtitle');
});

it('returns the legacy scalar title when title_i18n is null', function () {
    // A pre-localization row: only the scalar column is set, the JSON map is null.
    $course = Course::factory()->published()->create([
        'title' => 'Legacy Scalar Title',
        'title_i18n' => null,
        'subtitle_i18n' => null,
    ]);

    $data = $this->getJson('/api/v1/courses/'.$course->public_id.'?locale=ar')
        ->assertOk()
        ->json('data');

    expect($data['title'])->toBe('Legacy Scalar Title')
        ->and($data['title'])->toBeString();
});

it('never leaks a {en,ar} map into the response title field', function () {
    $course = publishedCourseWithTitles(['en' => 'Introduction to Programming', 'ar' => 'مقدمة في البرمجة']);

    $raw = $this->getJson('/api/v1/courses/'.$course->public_id.'?locale=en')
        ->assertOk()
        ->json('data');

    expect($raw['title'])->toBeString()
        ->and(is_array($raw['title']))->toBeFalse()
        ->and($raw['subtitle'])->toBeString();
});
