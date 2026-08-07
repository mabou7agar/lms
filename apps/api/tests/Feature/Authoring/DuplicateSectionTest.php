<?php

use App\Domains\Authoring\Database\Seeders\AuthoringSeeder;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\ContentVersion;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(AuthoringSeeder::class);
});

// function_exists-guarded helpers shared with DuplicateLessonTest — declared in both, defined once.
if (! function_exists('dupUser')) {
    function dupUser(string ...$roles): User
    {
        $user = User::factory()->create();
        foreach ($roles as $role) {
            $user->assignRole(SpatieRole::findByName($role, 'web'));
        }

        return $user;
    }
}

if (! function_exists('dupCourseFor')) {
    function dupCourseFor(?User $trainer = null): Course
    {
        $course = Course::factory()->create(['status' => CourseStatus::Draft]);
        if ($trainer !== null) {
            $course->syncTrainers([$trainer->id]);
        }

        return $course;
    }
}

it('duplicates a section with all its lessons, appended after existing sections, in Draft', function () {
    Sanctum::actingAs(dupUser('super_admin'));
    $course = Course::factory()->create();
    Section::factory()->create(['course_id' => $course->id, 'position' => 0]); // existing sibling
    $source = Section::factory()->published()->create([
        'course_id' => $course->id,
        'position' => 1,
        'title_i18n' => ['en' => 'Module A', 'ar' => 'الوحدة أ'],
    ]);
    Lesson::factory()->published()->create([
        'section_id' => $source->id,
        'position' => 0,
        'title_i18n' => ['en' => 'L1', 'ar' => 'د1'],
    ]);
    Lesson::factory()->create(['section_id' => $source->id, 'position' => 1]);

    $response = $this->postJson("/api/v1/admin/courses/{$course->public_id}/sections/{$source->public_id}/duplicate")
        ->assertCreated()
        ->assertJsonPath('data.publish_state', 'draft')
        ->assertJsonPath('data.title', 'Module A');

    $newId = $response->json('data.id');
    expect($newId)->not->toBe($source->public_id);

    $copy = Section::where('public_id', $newId)->firstOrFail();
    expect($copy->position)->toBe(2) // appended after positions 0 and 1
        ->and($copy->publish_state)->toBe(PublishState::Draft)
        ->and($copy->getAttribute('title_i18n'))->toEqual(['en' => 'Module A', 'ar' => 'الوحدة أ'])
        ->and($copy->lessons()->count())->toBe(2);

    // Copied lessons carry a fresh id, the bilingual title, and are reset to Draft even though the
    // source lesson was Published.
    $firstCopied = $copy->lessons()->orderBy('position')->first();
    expect($firstCopied->publish_state)->toBe(PublishState::Draft)
        ->and($firstCopied->getAttribute('title_i18n'))->toEqual(['en' => 'L1', 'ar' => 'د1']);

    // The source is untouched.
    expect($source->fresh()->publish_state)->toBe(PublishState::Published)
        ->and($source->lessons()->count())->toBe(2);
});

it('remaps intra-section prerequisites onto the copied lessons', function () {
    Sanctum::actingAs(dupUser('super_admin'));
    $course = Course::factory()->create();
    $source = Section::factory()->create(['course_id' => $course->id, 'position' => 0]);
    $a = Lesson::factory()->create(['section_id' => $source->id, 'position' => 0]);
    $b = Lesson::factory()->create(['section_id' => $source->id, 'position' => 1]);
    $b->prerequisites()->sync([$a->id]); // B depends on A, both inside the section

    $newId = $this->postJson("/api/v1/admin/courses/{$course->public_id}/sections/{$source->public_id}/duplicate")
        ->assertCreated()->json('data.id');

    $copy = Section::where('public_id', $newId)->firstOrFail();
    $copiedA = $copy->lessons()->where('position', 0)->firstOrFail();
    $copiedB = $copy->lessons()->where('position', 1)->firstOrFail();

    // B's copy depends on the COPY of A, never the original A.
    $prereqIds = $copiedB->prerequisites->pluck('id')->all();
    expect($prereqIds)->toBe([$copiedA->id])
        ->and($prereqIds)->not->toContain($a->id);
});

it('denies an instructor who does not own the course', function () {
    $instructor = dupUser('instructor');
    dupCourseFor($instructor);
    $course = dupCourseFor();
    $section = Section::factory()->create(['course_id' => $course->id]);
    Sanctum::actingAs($instructor);

    $this->postJson("/api/v1/admin/courses/{$course->public_id}/sections/{$section->public_id}/duplicate")
        ->assertForbidden();
});

it('404s when the section belongs to a different course', function () {
    $instructor = dupUser('instructor');
    $mine = dupCourseFor($instructor);
    $other = dupCourseFor();
    $foreignSection = Section::factory()->create(['course_id' => $other->id]);
    Sanctum::actingAs($instructor);

    $this->postJson("/api/v1/admin/courses/{$mine->public_id}/sections/{$foreignSection->public_id}/duplicate")
        ->assertNotFound();

    expect(Section::where('course_id', $mine->id)->count())->toBe(0);
});

it('records no content version', function () {
    Sanctum::actingAs(dupUser('super_admin'));
    $course = Course::factory()->create();
    $section = Section::factory()->create(['course_id' => $course->id]);
    Lesson::factory()->create(['section_id' => $section->id]);

    $this->postJson("/api/v1/admin/courses/{$course->public_id}/sections/{$section->public_id}/duplicate")
        ->assertCreated();

    expect(ContentVersion::count())->toBe(0);
});
