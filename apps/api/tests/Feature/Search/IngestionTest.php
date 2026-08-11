<?php

declare(strict_types=1);

use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QuestionAnswer;
use App\Platform\Identity\Models\User;
use App\Platform\Search\Ingestion\IngestionService;
use App\Platform\Search\Models\ContentEmbedding;
use App\Platform\Search\Providers\SearchServiceProvider;
use App\Platform\Shared\Search\Contracts\SearchIndexer;
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
    Http::preventStrayRequests(); // NO network: the FAKE embedding provider is used throughout.
});

it('embeds published course, lesson and Q&A content and stamps tenant, visibility and version', function () {
    app(TenantContext::class)->set(TenantId::from(1));

    $course = Course::factory()->published()->create(['title_i18n' => ['en' => 'Neural Networks Intro']]);
    $section = Section::factory()->published()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->published()->create(['section_id' => $section->id, 'title' => 'Backpropagation Lesson']);
    Block::factory()->published()->withContent(['en' => ['text' => 'gradient descent chain rule']])
        ->create(['lesson_id' => $lesson->id]);

    $asker = User::factory()->create();
    $answerer = User::factory()->create();
    $question = CourseQuestion::factory()->create(['course_id' => $course->id, 'user_id' => $asker->id, 'title' => 'Why normalise inputs']);
    QuestionAnswer::factory()->accepted()->create(['question_id' => $question->id, 'user_id' => $answerer->id, 'body' => '<p>It speeds convergence</p>']);

    Artisan::call('search:backfill');

    $courseRow = ContentEmbedding::query()->where('source_type', 'course')->firstOrFail();
    $lessonRow = ContentEmbedding::query()->where('source_type', 'lesson')->firstOrFail();
    $qnaRow = ContentEmbedding::query()->where('source_type', 'qna')->firstOrFail();

    // Tenant stamped from the owning content (org 1) on every source.
    expect($courseRow->organization_id)->toBe(1)
        ->and($lessonRow->organization_id)->toBe(1)
        ->and($qnaRow->organization_id)->toBe(1);

    // Audience visibility: catalogue is public; lesson + Q&A knowledge is authenticated-only.
    expect($courseRow->visibility)->toBe('public')
        ->and($lessonRow->visibility)->toBe('authenticated')
        ->and($qnaRow->visibility)->toBe('authenticated');

    // Version + deterministic embedding are stored.
    expect($courseRow->version)->toBeGreaterThan(0)
        ->and($courseRow->dims)->toBe(128)
        ->and($courseRow->model)->toBe('fake-embed-v1')
        ->and($courseRow->embedding)->toBeArray()->toHaveCount(128);

    // Q&A body was HTML-stripped before indexing.
    expect($qnaRow->chunk_text)->not->toContain('<p>');
});

it('never indexes unpublished or private content', function () {
    Course::factory()->create(['title_i18n' => ['en' => 'Draft Course']]);               // unpublished
    Course::factory()->published()->hidden()->create(['title_i18n' => ['en' => 'Secret Course']]); // private
    $public = Course::factory()->published()->create(['title_i18n' => ['en' => 'Public Course']]);  // indexable

    Artisan::call('search:backfill');

    // Only the published + public course is present in the index (identified by embeddable_id).
    $ids = ContentEmbedding::query()->where('source_type', 'course')->pluck('embeddable_id')->unique()->values();

    expect($ids->all())->toBe([$public->id]);
});

it('removes embeddings when content is deleted via the search indexer hook', function () {
    $course = Course::factory()->published()->create(['title_i18n' => ['en' => 'Removable Course']]);
    Artisan::call('search:backfill');

    expect(ContentEmbedding::query()->where('source_type', 'course')->where('embeddable_id', $course->id)->count())->toBeGreaterThan(0);

    app(SearchIndexer::class)->remove('course', $course->id);

    expect(ContentEmbedding::query()->where('source_type', 'course')->where('embeddable_id', $course->id)->count())->toBe(0);
});

it('re-indexes updated content, replacing the old rows', function () {
    $course = Course::factory()->published()->create(['title_i18n' => ['en' => 'Original Title']]);
    Artisan::call('search:backfill');

    $before = ContentEmbedding::query()->where('source_type', 'course')->where('embeddable_id', $course->id)->count();
    $originalText = ContentEmbedding::query()->where('source_type', 'course')->where('embeddable_id', $course->id)->pluck('chunk_text')->implode(' | ');
    expect($originalText)->toContain('original title');

    $course->update(['title_i18n' => ['en' => 'Rewritten Title']]);
    app(IngestionService::class)->reindex('course', $course->id);

    $after = ContentEmbedding::query()->where('source_type', 'course')->where('embeddable_id', $course->id)->get();
    $newText = $after->pluck('chunk_text')->implode(' | ');

    // Idempotent replace: same number of chunks, now reflecting the new title.
    expect($after)->toHaveCount($before)
        ->and($newText)->toContain('rewritten title')
        ->and($newText)->not->toContain('original title');
});

it('runs a bounded number of queries per re-index regardless of corpus size', function () {
    $course = Course::factory()->published()->create(['title_i18n' => ['en' => 'Bounded Course']]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(IngestionService::class)->reindex('course', $course->id);

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // load course + (delete old + insert new inside one transaction) — a small constant, not O(rows).
    expect($count)->toBeLessThanOrEqual(8);
});
