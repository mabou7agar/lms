<?php

use App\Domains\Catalog\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('finds a course by Arabic text that lives only in the i18n map, not the legacy scalar', function (): void {
    // Legacy `title` syncs from the default (en) locale, so the Arabic word exists only in
    // title_i18n.ar and search_text — the old ILIKE-over-legacy-scalar search could never find it.
    $arabic = Course::factory()->published()->create([
        'title_i18n' => ['en' => 'Python Course', 'ar' => 'دورة بايثون للمبتدئين'],
    ]);
    Course::factory()->published()->create(['title_i18n' => ['en' => 'Cooking Basics', 'ar' => 'أساسيات الطبخ']]);

    $res = $this->getJson('/api/v1/courses?q='.urlencode('بايثون'))->assertOk();

    expect($res->json('meta.total'))->toBe(1)
        ->and($res->json('data.0.id'))->toBe($arabic->public_id);
});

it('matches Arabic across letter and diacritic variants (alef, ta-marbuta, harakat)', function (): void {
    $course = Course::factory()->published()->create([
        'title_i18n' => ['en' => 'Academy', 'ar' => 'أكاديمِيّة البرمجة'],
    ]);

    // Query typed with a bare alef, no harakat, and ه instead of ة still matches.
    $res = $this->getJson('/api/v1/courses?q='.urlencode('اكاديميه'))->assertOk();

    expect($res->json('meta.total'))->toBe(1)
        ->and($res->json('data.0.id'))->toBe($course->public_id);
});

it('still searches English case-insensitively (no regression)', function (): void {
    $match = Course::factory()->published()->create(['title' => 'Laravel Mastery', 'title_i18n' => ['en' => 'Laravel Mastery']]);
    Course::factory()->published()->create(['title' => 'Cooking Basics', 'title_i18n' => ['en' => 'Cooking Basics']]);

    $res = $this->getJson('/api/v1/courses?q=LARAVEL')->assertOk();

    expect($res->json('meta.total'))->toBe(1)
        ->and($res->json('data.0.id'))->toBe($match->public_id);
});

it('treats LIKE metacharacters in the query as literal text', function (): void {
    $literal = Course::factory()->published()->create(['title' => 'Save 50% Today', 'title_i18n' => ['en' => 'Save 50% Today']]);
    Course::factory()->published()->create(['title' => 'Save 5000 Today', 'title_i18n' => ['en' => 'Save 5000 Today']]);

    // "50%" must match only the literal per-cent sign, not act as a wildcard that also hits "5000".
    $res = $this->getJson('/api/v1/courses?q='.urlencode('50%'))->assertOk();

    expect($res->json('meta.total'))->toBe(1)
        ->and($res->json('data.0.id'))->toBe($literal->public_id);
});

it('never exposes the internal search_text column through the public API', function (): void {
    Course::factory()->published()->create(['title' => 'Visible Course', 'title_i18n' => ['en' => 'Visible Course']]);

    $res = $this->getJson('/api/v1/courses')->assertOk();

    expect($res->json('data.0'))->not->toHaveKey('search_text');
});

it('maintains the folded search_text on the model when a course is saved', function (): void {
    $course = Course::factory()->published()->create([
        'title_i18n' => ['en' => 'Data Science', 'ar' => 'عِلم البيانات'],
    ]);

    // English folded (lowercased) and Arabic folded (harakat stripped) both land in one blob.
    expect($course->fresh()->getAttribute('search_text'))
        ->toContain('data science')
        ->toContain('علم البيانات');
});
