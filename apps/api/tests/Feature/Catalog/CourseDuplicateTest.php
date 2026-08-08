<?php

use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Actions\Course\DuplicateCourseAction;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseTag;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Enums\Role;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

/** An instructor (web-guard role, so it survives Sanctum switching the default guard). */
function dupInstructor(): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName(Role::Instructor->value, 'web'));

    return $user;
}

it('duplicates a course into an independent Draft with a new public_id/slug and " (Copy)" title', function () {
    $source = Course::factory()->published()->create([
        'title_i18n' => ['en' => 'Original', 'ar' => 'أصلي'],
        'is_featured' => true,
        'last_published_at' => now(),
        'scheduled_publish_at' => now()->addDay(),
    ]);

    $copy = app(DuplicateCourseAction::class)->execute($source);

    expect($copy->getKey())->not->toBe($source->getKey())
        ->and($copy->public_id)->not->toBe($source->public_id)
        ->and($copy->slug)->not->toBe($source->slug)
        ->and($copy->status)->toBe(CourseStatus::Draft)
        ->and($copy->is_featured)->toBeFalse()
        ->and($copy->published_at)->toBeNull()
        ->and($copy->last_published_at)->toBeNull()
        ->and($copy->scheduled_publish_at)->toBeNull()
        ->and($copy->title_i18n['en'])->toBe('Original (Copy)')
        ->and($copy->title_i18n['ar'])->toBe('أصلي (Copy)')
        ->and($copy->title)->toBe('Original (Copy)');
});

it('copies categories, tags, trainers (facets preserved) and gallery (same shared assets)', function () {
    $cat = Category::factory()->create();
    $tag = CourseTag::factory()->create();

    $source = Course::factory()->create(['title_i18n' => ['en' => 'Assoc Source']]);
    $source->categories()->sync([$cat->id]);
    $source->tags()->sync([$tag->id]);
    $t1 = User::factory()->create();
    $t2 = User::factory()->create();
    $source->syncTrainers([$t1->id, $t2->id]);
    DB::table('course_trainer')->where('course_id', $source->id)->where('user_id', $t1->id)
        ->update(['role' => 'lead', 'position' => 2, 'is_primary' => true]);
    $source->galleryItems()->create(['media_public_id' => 'shared-asset-1', 'position' => 1]);

    $copy = app(DuplicateCourseAction::class)->execute($source);

    expect($copy->categories()->pluck('categories.id')->all())->toBe([$cat->id])
        ->and($copy->tags()->pluck('course_tags.id')->all())->toBe([$tag->id])
        ->and($copy->galleryItems()->pluck('media_public_id')->all())->toBe(['shared-asset-1']);

    // Trainer facets travelled verbatim; the primary flag is preserved.
    $primary = DB::table('course_trainer')->where('course_id', $copy->id)->where('user_id', $t1->id)->first();
    expect($primary)->not->toBeNull()
        ->and($primary->role)->toBe('lead')
        ->and((int) $primary->position)->toBe(2)
        ->and((bool) $primary->is_primary)->toBeTrue()
        ->and(DB::table('course_trainer')->where('course_id', $copy->id)->count())->toBe(2);
});

it('forks the curriculum into the copy with fresh ids, leaving the source untouched', function () {
    $source = Course::factory()->create(['title_i18n' => ['en' => 'Curriculum Source']]);
    $section = Section::factory()->published()->create(['course_id' => $source->id, 'title' => 'Sec 1', 'position' => 1]);
    Lesson::factory()->published()->create(['section_id' => $section->id, 'title' => 'Lesson 1', 'position' => 1]);

    $copy = app(DuplicateCourseAction::class)->execute($source);

    $copySections = Section::where('course_id', $copy->id)->get();
    $copyLessons = Lesson::whereIn('section_id', $copySections->pluck('id'))->get();

    expect($copySections)->toHaveCount(1)
        ->and($copySections->first()->title)->toBe('Sec 1')
        ->and($copySections->first()->id)->not->toBe($section->id)
        ->and($copyLessons)->toHaveCount(1)
        ->and($copyLessons->first()->title)->toBe('Lesson 1')
        // Source curriculum is unchanged.
        ->and(Section::where('course_id', $source->id)->count())->toBe(1);
});

it('never mutates the source when the copy is later edited', function () {
    $source = Course::factory()->create(['title_i18n' => ['en' => 'Immutable Source']]);
    $source->categories()->sync([Category::factory()->create()->id]);

    $copy = app(DuplicateCourseAction::class)->execute($source);
    $copy->update(['title_i18n' => ['en' => 'Edited Copy']]);
    $copy->categories()->sync([]);

    expect($source->fresh()->title_i18n['en'])->toBe('Immutable Source')
        ->and($source->categories()->count())->toBe(1);
});

it('stamps the copy with the acting tenant server-side, not the source org', function () {
    // A GLOBAL source (organization_id NULL); the copy must be stamped from the resolved tenant.
    $source = Course::factory()->create(['title_i18n' => ['en' => 'Global Source']]);
    expect($source->organization_id)->toBeNull();

    app(TenantContext::class)->set(TenantId::from(1));
    $copy = app(DuplicateCourseAction::class)->execute($source);

    // organization_id is derived server-side (never copied from the source, never client-forgeable).
    expect((int) $copy->organization_id)->toBe(1)
        ->and($copy->belongsToTenant(TenantId::from(1)))->toBeTrue();
});

it('exposes an ownership-scoped instructor duplicate endpoint', function () {
    $me = dupInstructor();
    $source = Course::factory()->published()->create(['title_i18n' => ['en' => 'My Course']]);
    $source->syncTrainers([$me->id]);

    Sanctum::actingAs($me);
    $res = $this->postJson('/api/v1/teach/courses/'.$source->public_id.'/duplicate')->assertOk();

    $copyId = $res->json('data.id');
    expect($copyId)->not->toBe($source->public_id)
        ->and($res->json('data.status'))->toBe(CourseStatus::Draft->value);

    $copy = Course::where('public_id', $copyId)->first();
    expect($copy)->not->toBeNull()
        ->and($copy->status)->toBe(CourseStatus::Draft);
});

it('404s when a non-owning instructor tries to duplicate a course', function () {
    $owner = dupInstructor();
    $source = Course::factory()->published()->create();
    $source->syncTrainers([$owner->id]);

    Sanctum::actingAs(dupInstructor());
    $this->postJson('/api/v1/teach/courses/'.$source->public_id.'/duplicate')->assertStatus(404);
});
