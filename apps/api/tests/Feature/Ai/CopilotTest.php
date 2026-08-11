<?php

declare(strict_types=1);

use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Platform\AI\Models\AiPrompt;
use App\Platform\AI\Models\AiUsage;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class); // roles (instructor, admin, ...)
    config(['ai.enabled' => true, 'ai.default_provider' => 'fake']);
    Http::preventStrayRequests();

    AiPrompt::factory()->create([
        'key' => 'copilot.assist', 'version' => 1, 'active' => true, 'locale' => 'en',
        'system_prompt' => 'You assist an instructor. Suggestions only; never grade or write records.',
        'user_template' => 'Task: {{ task }} Brief: {{ brief }} Context: {{ context }}',
    ]);
});

function copilotInstructor(): User
{
    $user = User::factory()->create();
    $user->assignRole('instructor');

    return $user;
}

/**
 * A published course trained by $owner, with one published lesson carrying $text.
 */
function copilotCourse(User $owner, string $text): Course
{
    $course = Course::factory()->published()->create();
    $course->syncTrainers([$owner->id]);

    $section = Section::factory()->published()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->published()->create(['section_id' => $section->id, 'title' => 'Lesson']);
    Block::factory()->published()->withContent(['en' => ['text' => $text]])->create(['lesson_id' => $lesson->id]);

    return $course;
}

it('produces a grounded suggestion for the owning instructor and records copilot usage', function () {
    $owner = copilotInstructor();
    $course = copilotCourse($owner, 'gradient descent minimizes a loss function iteratively');
    Artisan::call('search:backfill');

    $data = $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/ai/copilot', [
            'course_id' => $course->public_id,
            'mode' => 'draft_lesson',
            'brief' => 'gradient descent minimizes a loss function iteratively',
        ])
        ->assertOk()
        ->json('data');

    expect($data['refused'])->toBeFalse()
        ->and($data['label'])->toBe('AI-generated')
        ->and($data['citations'])->not->toBeEmpty();

    $row = AiUsage::query()->where('feature', 'copilot')->firstOrFail();
    expect($row->prompt_key)->toBe('copilot.assist')
        ->and($row->user_id)->toBe($owner->id)
        ->and($row->input_tokens)->toBeGreaterThan(0);
});

it('blocks an instructor who does not own the course (404, indistinguishable from missing)', function () {
    $owner = copilotInstructor();
    $course = copilotCourse($owner, 'backpropagation propagates error gradients through the network');
    Artisan::call('search:backfill');

    $other = copilotInstructor(); // an instructor, but not a trainer of this course

    $this->actingAs($other, 'sanctum')
        ->postJson('/api/v1/ai/copilot', [
            'course_id' => $course->public_id,
            'mode' => 'summarize_questions',
            'brief' => 'anything',
        ])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'COURSE_NOT_FOUND');

    expect(AiUsage::query()->count())->toBe(0);
});

it('never writes to any student / enrollment record', function () {
    $owner = copilotInstructor();
    $course = copilotCourse($owner, 'regularization reduces overfitting by penalizing large weights');
    Artisan::call('search:backfill');

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/v1/ai/copilot', [
            'course_id' => $course->public_id,
            'mode' => 'suggest_content',
            'brief' => 'regularization reduces overfitting by penalizing large weights',
        ])
        ->assertOk();

    // The copilot is read-only over learner data: it grounds + drafts, it never enrolls or grades.
    expect(Enrollment::query()->count())->toBe(0);
});
