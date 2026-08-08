<?php

use App\Domains\Catalog\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A published, publicly-visible course carrying the full marketing surface in EN + AR. */
function marketingCourse(): Course
{
    return Course::factory()->published()->create([
        'title_i18n' => ['en' => 'Marketing Course', 'ar' => 'دورة تسويقية'],
        'learning_objectives_i18n' => [
            'en' => ['Build REST APIs', 'Write tests'],
            'ar' => ['بناء واجهات', 'كتابة اختبارات'],
        ],
        'requirements_i18n' => [
            'en' => ['Basic PHP'],
            'ar' => ['أساسيات PHP'],
        ],
        'target_audience_i18n' => [
            'en' => ['Backend developers'],
            'ar' => ['مطورو الخلفية'],
        ],
        'duration_minutes' => 240,
        'trailer_path' => 'https://cdn.example.com/promo/trailer.mp4',
    ]);
}

it('persists the marketing list fields as i18n maps and duration/trailer scalars', function () {
    $course = marketingCourse()->fresh();

    expect($course->learning_objectives_i18n)->toEqual([
        'en' => ['Build REST APIs', 'Write tests'],
        'ar' => ['بناء واجهات', 'كتابة اختبارات'],
    ])
        ->and($course->requirements_i18n['en'])->toBe(['Basic PHP'])
        ->and($course->target_audience_i18n['ar'])->toBe(['مطورو الخلفية'])
        ->and($course->duration_minutes)->toBe(240)
        ->and($course->trailer_path)->toBe('https://cdn.example.com/promo/trailer.mp4');
});

it('returns the marketing fields resolved to EN on the public course detail (no {en,ar} leak)', function () {
    $course = marketingCourse();

    $res = $this->getJson('/api/v1/courses/'.$course->public_id.'?locale=en')->assertOk();

    expect($res->json('data.learning_objectives'))->toBe(['Build REST APIs', 'Write tests'])
        ->and($res->json('data.requirements'))->toBe(['Basic PHP'])
        ->and($res->json('data.target_audience'))->toBe(['Backend developers'])
        ->and($res->json('data.duration_minutes'))->toBe(240)
        // The resolved value is a plain list — the raw {en,ar} map never leaks.
        ->and($res->json('data.learning_objectives'))->not->toContain('بناء واجهات')
        ->and(array_keys((array) $res->json('data.learning_objectives')))->toBe([0, 1]);
});

it('returns the marketing fields resolved to AR when the request locale is Arabic', function () {
    $course = marketingCourse();

    $res = $this->getJson('/api/v1/courses/'.$course->public_id.'?locale=ar')->assertOk();

    expect($res->json('data.learning_objectives'))->toBe(['بناء واجهات', 'كتابة اختبارات'])
        ->and($res->json('data.requirements'))->toBe(['أساسيات PHP'])
        ->and($res->json('data.target_audience'))->toBe(['مطورو الخلفية']);
});

it('resolves the trailer to a playback-safe URL exactly like the thumbnail (legacy passthrough)', function () {
    $course = marketingCourse();

    $res = $this->getJson('/api/v1/courses/'.$course->public_id)->assertOk();

    expect($res->json('data.trailer'))->toBe('https://cdn.example.com/promo/trailer.mp4');
});

it('returns empty marketing lists (never a null/map) when none are set', function () {
    $course = Course::factory()->published()->create(['title_i18n' => ['en' => 'Bare Course']]);

    $res = $this->getJson('/api/v1/courses/'.$course->public_id)->assertOk();

    expect($res->json('data.learning_objectives'))->toBe([])
        ->and($res->json('data.requirements'))->toBe([])
        ->and($res->json('data.target_audience'))->toBe([])
        ->and($res->json('data.duration_minutes'))->toBeNull()
        ->and($res->json('data.trailer'))->toBeNull();
});
