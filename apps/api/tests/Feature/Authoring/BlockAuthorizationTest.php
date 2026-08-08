<?php

use App\Domains\Authoring\Database\Seeders\AuthoringSeeder;
use App\Domains\Authoring\Models\Block;
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
    config()->set('authoring.blocks_enabled', true);
});

if (! function_exists('blkUser')) {
    function blkUser(string ...$roles): User
    {
        $user = User::factory()->create();
        foreach ($roles as $role) {
            $user->assignRole(SpatieRole::findByName($role, 'web'));
        }

        return $user;
    }
}

if (! function_exists('blkCourseFor')) {
    function blkCourseFor(?User $trainer = null): Course
    {
        $course = Course::factory()->create(['status' => CourseStatus::Draft]);
        if ($trainer !== null) {
            $course->syncTrainers([$trainer->id]);
        }

        return $course;
    }
}

it('404s a cross-lesson duplicate forgery (block belongs to another lesson in the same course)', function () {
    $instructor = blkUser('instructor');
    $course = blkCourseFor($instructor);
    $section = Section::factory()->create(['course_id' => $course->id]);
    $lessonA = Lesson::factory()->create(['section_id' => $section->id]);
    $lessonB = Lesson::factory()->create(['section_id' => $section->id]);
    $foreignBlock = Block::factory()->for($lessonB)->create(['type' => 'article', 'position' => 0]);
    Sanctum::actingAs($instructor);

    // Duplicate is addressed under lessonA but the block lives in lessonB -> mismatch -> 404.
    $this->postJson("/api/v1/admin/lessons/{$lessonA->public_id}/blocks/{$foreignBlock->public_id}/duplicate")
        ->assertNotFound();

    // Nothing was copied into lessonA.
    expect(Block::where('lesson_id', $lessonA->id)->count())->toBe(0);
});

it('403s a cross-course block edit forgery (block belongs to an unowned course)', function () {
    $instructor = blkUser('instructor');
    blkCourseFor($instructor); // owns *a* course, but not the one below
    $other = blkCourseFor();    // no trainer assignment
    $otherSection = Section::factory()->create(['course_id' => $other->id]);
    $otherLesson = Lesson::factory()->create(['section_id' => $otherSection->id]);
    $foreignBlock = Block::factory()->for($otherLesson)->create(['type' => 'article', 'position' => 0]);
    Sanctum::actingAs($instructor);

    // The policy resolves block -> lesson -> section -> course and denies the unowned course.
    $this->putJson("/api/v1/admin/blocks/{$foreignBlock->public_id}", [
        'content_i18n' => ['en' => ['html' => '<p>tamper</p>']],
    ])->assertForbidden();

    $this->deleteJson("/api/v1/admin/blocks/{$foreignBlock->public_id}")->assertForbidden();
    $this->postJson("/api/v1/admin/blocks/{$foreignBlock->public_id}/publish", ['state' => 'published'])
        ->assertForbidden();
});

it('403s creating a block under a lesson in an unowned course', function () {
    $instructor = blkUser('instructor');
    blkCourseFor($instructor);
    $other = blkCourseFor();
    $otherSection = Section::factory()->create(['course_id' => $other->id]);
    $otherLesson = Lesson::factory()->create(['section_id' => $otherSection->id]);
    Sanctum::actingAs($instructor);

    $this->postJson("/api/v1/admin/lessons/{$otherLesson->public_id}/blocks", [
        'type' => 'article',
        'content_i18n' => ['en' => ['html' => '<p>x</p>']],
    ])->assertForbidden();

    expect(Block::count())->toBe(0);
});
