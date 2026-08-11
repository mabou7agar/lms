<?php

declare(strict_types=1);

use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Platform\Search\Data\VectorQuery;
use App\Platform\Search\Models\ContentEmbedding;
use App\Platform\Search\Providers\SearchServiceProvider;
use App\Platform\Search\Search\HybridSearchService;
use App\Platform\Search\Search\SearchHit;
use App\Platform\Shared\Search\Enums\SearchSourceType;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(SearchServiceProvider::class);
    Artisan::call('migrate', ['--force' => true]);
    config(['ai.enabled' => true, 'ai.default_provider' => 'fake']);
    Http::preventStrayRequests();
});

/** @param list<SearchHit> $hits */
function hitIds(array $hits): array
{
    return array_map(static fn (SearchHit $h): int => $h->embeddableId, $hits);
}

function publicCourseQuery(?int $org = null): VectorQuery
{
    return new VectorQuery(
        organizationId: $org,
        visibilities: ['public'],
        locales: [],
        sourceTypes: [SearchSourceType::Course->value],
    );
}

it('returns the relevant course for a semantic (reordered) query', function () {
    $a = Course::factory()->published()->create(['description_i18n' => ['en' => 'reinforcement learning policy gradients']]);
    Course::factory()->published()->create(['description_i18n' => ['en' => 'culinary baking bread techniques']]);
    Course::factory()->published()->create(['description_i18n' => ['en' => 'ancient roman military history']]);

    Artisan::call('search:backfill');

    // A full word-order reorder of A's description: no literal substring, but the same token set.
    $hits = app(HybridSearchService::class)->search('gradients policy learning reinforcement', publicCourseQuery());

    expect($hits)->not->toBeEmpty()
        ->and($hits[0]->embeddableId)->toBe($a->id)
        ->and($hits[0]->matchedSemantic)->toBeTrue();
});

it('hybrid beats keyword-only on a paraphrased (reordered) query', function () {
    $a = Course::factory()->published()->create(['description_i18n' => ['en' => 'advanced python programming fundamentals']]);

    Artisan::call('search:backfill');

    $reordered = 'fundamentals programming advanced python';

    // Keyword-only (semantic arm disabled): a reordered phrase is not a literal substring -> miss.
    $keywordOnly = app(HybridSearchService::class)->search($reordered, publicCourseQuery(), semanticWeight: 0.0, keywordWeight: 1.0);

    // Hybrid (default weights): the semantic arm recovers the reordered paraphrase.
    $hybrid = app(HybridSearchService::class)->search($reordered, publicCourseQuery());

    expect(hitIds($keywordOnly))->not->toContain($a->id)
        ->and(hitIds($hybrid))->toContain($a->id);
});

it('matches an Arabic query against normalized content (bilingual)', function () {
    $a = Course::factory()->published()->create([
        'title_i18n' => ['en' => 'Python Course', 'ar' => 'دورة بايثون للمبتدئين'],
    ]);

    Artisan::call('search:backfill');

    // Bare Arabic word (no diacritics) matches the normalized Arabic title chunk via the keyword arm.
    $hits = app(HybridSearchService::class)->search('بايثون', publicCourseQuery());

    expect(hitIds($hits))->toContain($a->id);
});

it('never leaks another tenant\'s course into a tenant-scoped public search', function () {
    $tenant = app(TenantContext::class);

    $tenant->set(TenantId::from(1));
    $a = Course::factory()->published()->create(['description_i18n' => ['en' => 'machine learning basics']]);

    $tenant->set(TenantId::from(2));
    $b = Course::factory()->published()->create(['description_i18n' => ['en' => 'machine learning basics']]);

    $tenant->forget();
    Artisan::call('search:backfill');

    $query = 'basics learning machine'; // reorder -> semantic finds both same-token courses

    $forOrg1 = hitIds(app(HybridSearchService::class)->search($query, publicCourseQuery(org: 1)));
    $forOrg2 = hitIds(app(HybridSearchService::class)->search($query, publicCourseQuery(org: 2)));

    expect($forOrg1)->toContain($a->id)->not->toContain($b->id)
        ->and($forOrg2)->toContain($b->id)->not->toContain($a->id);
});

it('keeps private, unpublished and authenticated content out of public course search', function () {
    $public = Course::factory()->published()->create(['description_i18n' => ['en' => 'deep learning networks']]);
    $private = Course::factory()->published()->hidden()->create(['description_i18n' => ['en' => 'deep learning networks']]);
    $draft = Course::factory()->create(['description_i18n' => ['en' => 'deep learning networks']]); // unpublished

    // A published lesson carrying the same words (authenticated-audience knowledge).
    $host = Course::factory()->published()->create(['title_i18n' => ['en' => 'Host']]);
    $section = Section::factory()->published()->create(['course_id' => $host->id]);
    $lesson = Lesson::factory()->published()->create(['section_id' => $section->id, 'title' => 'Module']);
    Block::factory()->published()->withContent(['en' => ['text' => 'deep learning networks']])->create(['lesson_id' => $lesson->id]);

    Artisan::call('search:backfill');

    $query = 'deep learning networks';

    // Public catalogue search: only the published + public course, never private/unpublished/lesson.
    $public_hits = app(HybridSearchService::class)->search($query, publicCourseQuery());
    $publicIds = hitIds($public_hits);

    expect($publicIds)->toContain($public->id)
        ->and(collect($public_hits)->pluck('sourceType')->unique()->all())->toBe(['course']);

    // Private + unpublished courses were never indexed at all; the public one was.
    expect(ContentEmbedding::query()->where('embeddable_id', $public->id)->where('source_type', 'course')->count())->toBeGreaterThan(0)
        ->and(ContentEmbedding::query()->where('embeddable_id', $private->id)->where('source_type', 'course')->count())->toBe(0)
        ->and(ContentEmbedding::query()->where('embeddable_id', $draft->id)->where('source_type', 'course')->count())->toBe(0);

    // Authenticated knowledge search additionally surfaces the lesson.
    $knowledge = new VectorQuery(null, ['public', 'authenticated'], [], SearchSourceType::values());
    $knowledgeSources = collect(app(HybridSearchService::class)->search($query, $knowledge))
        ->pluck('sourceType')->unique()->all();

    expect($knowledgeSources)->toContain('lesson');
});

it('runs a bounded number of queries per search regardless of corpus size', function () {
    Course::factory()->count(15)->published()->create(['description_i18n' => ['en' => 'shared filler tokens here']]);
    Artisan::call('search:backfill');

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(HybridSearchService::class)->search('filler shared tokens here', publicCourseQuery());

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // One candidate select (semantic) + one keyword select — constant, not O(courses).
    expect($count)->toBeLessThanOrEqual(4);
});
