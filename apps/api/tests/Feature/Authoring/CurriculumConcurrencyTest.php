<?php

use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Authoring\Services\ContentVersioningService;
use App\Domains\Authoring\Snapshots\SnapshotSerializer;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    Sanctum::actingAs($admin);
    $this->admin = $admin;
});

/*
 |--------------------------------------------------------------------------
 | C3 - Optimistic locking / lost-update protection
 |--------------------------------------------------------------------------
 | Asserted at the HTTP/controller boundary, matching CurriculumManagementTest.
 */

it('rejects the second of two stale editors updating the same section and preserves the first write', function () {
    $course = Course::factory()->create();
    $section = Section::factory()->create(['course_id' => $course->id, 'title' => 'Original']);

    // Both editors loaded the section at lock_version 0.
    $first = $this->putJson("/api/v1/admin/sections/{$section->public_id}", [
        'title' => 'Edited by A',
        'expected_version' => 0,
    ])->assertOk();

    expect($first->json('data.lock_version'))->toBe(1)
        ->and($first->json('data.title'))->toBe('Edited by A');

    // Second editor still believes it holds version 0 -> stale write, rejected, nothing changes.
    $this->putJson("/api/v1/admin/sections/{$section->public_id}", [
        'title' => 'Edited by B',
        'expected_version' => 0,
    ])->assertStatus(409)
        ->assertExactJson(['error' => 'stale_write', 'current_version' => 1]);

    // First writer's state is intact and the counter did not advance a second time.
    $fresh = $section->fresh();
    expect($fresh->title)->toBe('Edited by A')
        ->and($fresh->lock_version)->toBe(1);
});

it('returns the exact 409 stale_write body shape for a stale lesson update', function () {
    $course = Course::factory()->create();
    $section = Section::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['section_id' => $section->id, 'title' => 'Intro']);

    // Advance to version 1.
    $this->putJson("/api/v1/admin/lessons/{$lesson->public_id}", [
        'title' => 'Intro v2',
        'expected_version' => 0,
    ])->assertOk()->assertJsonPath('data.lock_version', 1);

    // Stale attempt: body is exactly { "error":"stale_write", "current_version":1 }.
    $this->putJson("/api/v1/admin/lessons/{$lesson->public_id}", [
        'title' => 'Intro v3',
        'expected_version' => 0,
    ])->assertStatus(409)
        ->assertExactJson(['error' => 'stale_write', 'current_version' => 1]);

    expect($lesson->fresh()->title)->toBe('Intro v2');
});

it('rejects a stale lesson reorder and preserves the first writer ordering', function () {
    $course = Course::factory()->create();
    $section = Section::factory()->create(['course_id' => $course->id]);
    $a = Lesson::factory()->create(['section_id' => $section->id, 'position' => 0, 'title' => 'A']);
    $b = Lesson::factory()->create(['section_id' => $section->id, 'position' => 1, 'title' => 'B']);

    // Editor A reorders to [B, A] from version 0 -> section advances to version 1.
    $this->putJson("/api/v1/admin/sections/{$section->public_id}/lessons/order", [
        'order' => [$b->public_id, $a->public_id],
        'expected_version' => 0,
    ])->assertOk()->assertJsonPath('data.lock_version', 1);

    // Editor B still holds version 0 -> stale reorder rejected, first writer's order stands.
    $this->putJson("/api/v1/admin/sections/{$section->public_id}/lessons/order", [
        'order' => [$a->public_id, $b->public_id],
        'expected_version' => 0,
    ])->assertStatus(409)
        ->assertExactJson(['error' => 'stale_write', 'current_version' => 1]);

    expect($b->fresh()->position)->toBe(0)
        ->and($a->fresh()->position)->toBe(1)
        ->and($section->fresh()->lock_version)->toBe(1);
});

it('keeps section reorder deterministic and server-authoritative under serialized writes', function () {
    $course = Course::factory()->create();
    $s1 = Section::factory()->create(['course_id' => $course->id, 'position' => 0, 'title' => 'S1']);
    $s2 = Section::factory()->create(['course_id' => $course->id, 'position' => 1, 'title' => 'S2']);
    $s3 = Section::factory()->create(['course_id' => $course->id, 'position' => 2, 'title' => 'S3']);

    // Two racing reorders serialize via the course row lock; the last committed order wins and the
    // final positions are always a clean contiguous 0..n-1 with no duplicates or gaps.
    $this->putJson("/api/v1/admin/courses/{$course->public_id}/sections/order", [
        'order' => [$s3->public_id, $s1->public_id, $s2->public_id],
    ])->assertOk();

    $this->putJson("/api/v1/admin/courses/{$course->public_id}/sections/order", [
        'order' => [$s2->public_id, $s3->public_id, $s1->public_id],
    ])->assertOk();

    // Final = last writer's order, authoritatively assigned by the server.
    expect($s2->fresh()->position)->toBe(0)
        ->and($s3->fresh()->position)->toBe(1)
        ->and($s1->fresh()->position)->toBe(2);

    $positions = Section::where('course_id', $course->id)->orderBy('position')->pluck('position')->all();
    expect($positions)->toBe([0, 1, 2]); // contiguous, unique
});

it('returns an incremented lock_version on a successful guarded write', function () {
    $course = Course::factory()->create();
    $section = Section::factory()->create(['course_id' => $course->id]);

    $this->putJson("/api/v1/admin/sections/{$section->public_id}", [
        'title' => 'First',
        'expected_version' => 0,
    ])->assertOk()->assertJsonPath('data.lock_version', 1);

    $this->putJson("/api/v1/admin/sections/{$section->public_id}", [
        'title' => 'Second',
        'expected_version' => 1,
    ])->assertOk()->assertJsonPath('data.lock_version', 2);
});

it('stays backward compatible when expected_version is absent (last-write-wins)', function () {
    $course = Course::factory()->create();
    $section = Section::factory()->create(['course_id' => $course->id, 'title' => 'Original']);

    // No expected_version supplied -> no optimistic check, existing behaviour preserved.
    $this->putJson("/api/v1/admin/sections/{$section->public_id}", ['title' => 'Edit one'])
        ->assertOk()->assertJsonPath('data.lock_version', 1);

    // A second writer, also without a version, is NOT rejected — it simply overwrites.
    $this->putJson("/api/v1/admin/sections/{$section->public_id}", ['title' => 'Edit two'])
        ->assertOk()->assertJsonPath('data.lock_version', 2);

    expect($section->fresh()->title)->toBe('Edit two');
});

it('leaves lesson reorder backward compatible without a version and still advances the counter', function () {
    $course = Course::factory()->create();
    $section = Section::factory()->create(['course_id' => $course->id]);
    $a = Lesson::factory()->create(['section_id' => $section->id, 'position' => 0]);
    $b = Lesson::factory()->create(['section_id' => $section->id, 'position' => 1]);

    $this->putJson("/api/v1/admin/sections/{$section->public_id}/lessons/order", [
        'order' => [$b->public_id, $a->public_id],
    ])->assertOk()->assertJsonPath('data.lock_version', 1);

    expect($b->fresh()->position)->toBe(0);
});

it('keeps content version history valid and appended after guarded writes', function () {
    $course = Course::factory()->create();
    $section = Section::factory()->create(['course_id' => $course->id]);
    Lesson::factory()->create(['section_id' => $section->id, 'position' => 0, 'title' => 'L0']);

    /** @var ContentVersioningService $versioning */
    $versioning = app(ContentVersioningService::class);

    // A guarded update advances lock_version...
    $this->putJson("/api/v1/admin/sections/{$section->public_id}", [
        'title' => 'Renamed',
        'expected_version' => 0,
    ])->assertOk()->assertJsonPath('data.lock_version', 1);

    // ...and a snapshot taken afterwards is still self-consistent (checksum verifies) and does NOT
    // leak the optimistic-lock counter into the immutable snapshot payload.
    $v1 = $versioning->createSnapshot((int) $course->id, (int) $this->admin->id, 'After edit', false);

    expect($v1->version_number)->toBe(1)
        ->and(SnapshotSerializer::checksum($v1->snapshot))->toBe($v1->checksum)
        ->and($v1->snapshot['sections'][0])->not->toHaveKey('lock_version')
        ->and($v1->snapshot['sections'][0]['title'])->toBe('Renamed');

    // A further edit + snapshot appends a new, monotonically numbered version — history intact.
    $this->putJson("/api/v1/admin/sections/{$section->public_id}", [
        'title' => 'Renamed again',
        'expected_version' => 1,
    ])->assertOk()->assertJsonPath('data.lock_version', 2);

    $v2 = $versioning->createSnapshot((int) $course->id, (int) $this->admin->id, 'After second edit', false);

    expect($v2->version_number)->toBe(2)
        ->and(SnapshotSerializer::checksum($v2->snapshot))->toBe($v2->checksum);
});
