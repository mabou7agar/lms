<?php

use App\Domains\Authoring\Database\Seeders\AuthoringSeeder;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\ContentVersion;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\LessonMedia;
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
    // AuthoringSeeder grants the global manage permission to ADMIN only; instructor access comes
    // solely from trainer ownership — exactly what the authorization cases below exercise.
    $this->seed(AuthoringSeeder::class);
});

// Uniquely-named, function_exists-guarded helpers so they never clash with the identically-purposed
// helpers in InstructorCurriculumAccessTest when both files load in the same worker.
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

it('duplicates a lesson with a fresh id, the bilingual title, media, an appended position and Draft state', function () {
    Sanctum::actingAs(dupUser('super_admin'));
    $course = Course::factory()->create();
    $section = Section::factory()->create(['course_id' => $course->id]);

    // An existing lesson so the copy must append after it.
    Lesson::factory()->create(['section_id' => $section->id, 'position' => 0]);
    $source = Lesson::factory()->published()->create([
        'section_id' => $section->id,
        'position' => 1,
        'title_i18n' => ['en' => 'Welcome', 'ar' => 'أهلا'],
        'content' => ['body' => 'Hello'],
    ]);
    LessonMedia::factory()->create(['lesson_id' => $source->id, 'mux_asset_id' => 'asset_x']);

    $response = $this->postJson("/api/v1/admin/sections/{$section->public_id}/lessons/{$source->public_id}/duplicate")
        ->assertCreated()
        ->assertJsonPath('data.publish_state', 'draft')
        ->assertJsonPath('data.title', 'Welcome');

    $newId = $response->json('data.id');
    expect($newId)->not->toBe($source->public_id);

    $copy = Lesson::where('public_id', $newId)->firstOrFail();
    expect($copy->section_id)->toBe($source->section_id)
        ->and($copy->getAttribute('title_i18n'))->toEqual(['en' => 'Welcome', 'ar' => 'أهلا'])
        ->and($copy->content)->toEqual(['body' => 'Hello'])
        ->and($copy->position)->toBe(2) // appended after positions 0 and 1
        ->and($copy->publish_state)->toBe(PublishState::Draft)
        ->and($copy->media)->not->toBeNull()
        ->and($copy->media->mux_asset_id)->toBe('asset_x');

    // The source is untouched — the duplicate is not published, and nothing about the original moved.
    expect($source->fresh()->publish_state)->toBe(PublishState::Published);
});

it('copies same-course prerequisites onto the duplicated lesson', function () {
    Sanctum::actingAs(dupUser('super_admin'));
    $course = Course::factory()->create();
    $section = Section::factory()->create(['course_id' => $course->id]);
    $prereq = Lesson::factory()->create(['section_id' => $section->id, 'position' => 0]);
    $source = Lesson::factory()->create(['section_id' => $section->id, 'position' => 1]);
    $source->prerequisites()->sync([$prereq->id]);

    $newId = $this->postJson("/api/v1/admin/sections/{$section->public_id}/lessons/{$source->public_id}/duplicate")
        ->assertCreated()->json('data.id');

    $copy = Lesson::where('public_id', $newId)->firstOrFail();
    expect($copy->prerequisites->pluck('id')->all())->toBe([$prereq->id]);
});

it('denies an instructor who does not own the course', function () {
    $instructor = dupUser('instructor');
    dupCourseFor($instructor);   // owns *a* course, but not the one below
    $course = dupCourseFor();     // no trainer assignment
    $section = Section::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['section_id' => $section->id]);
    Sanctum::actingAs($instructor);

    $this->postJson("/api/v1/admin/sections/{$section->public_id}/lessons/{$lesson->public_id}/duplicate")
        ->assertForbidden();
});

it('404s when the lesson belongs to a section in a different (unowned) course', function () {
    $instructor = dupUser('instructor');
    $mine = dupCourseFor($instructor);
    $mySection = Section::factory()->create(['course_id' => $mine->id]);
    $other = dupCourseFor(); // not owned
    $otherSection = Section::factory()->create(['course_id' => $other->id]);
    $foreignLesson = Lesson::factory()->create(['section_id' => $otherSection->id]);
    Sanctum::actingAs($instructor);

    $this->postJson("/api/v1/admin/sections/{$mySection->public_id}/lessons/{$foreignLesson->public_id}/duplicate")
        ->assertNotFound();

    // Nothing was copied into the caller's own section.
    expect(Lesson::where('section_id', $mySection->id)->count())->toBe(0);
});

it('records no content version, mirroring the create path', function () {
    Sanctum::actingAs(dupUser('super_admin'));
    $course = Course::factory()->create();
    $section = Section::factory()->create(['course_id' => $course->id]);
    $source = Lesson::factory()->create(['section_id' => $section->id]);

    $this->postJson("/api/v1/admin/sections/{$section->public_id}/lessons/{$source->public_id}/duplicate")
        ->assertCreated();

    expect(ContentVersion::count())->toBe(0);
});
