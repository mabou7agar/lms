<?php

use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
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

    config()->set('authoring.blocks_enabled', true);

    $course = Course::factory()->create();
    $this->section = Section::factory()->create(['course_id' => $course->id]);
    $this->lesson = Lesson::factory()->create(['section_id' => $this->section->id]);
});

it('returns the exact 409 stale_write body for a stale block edit and preserves the first write', function () {
    $block = Block::factory()->for($this->lesson)->create([
        'type' => 'article',
        'position' => 0,
        'content_i18n' => ['en' => ['html' => '<p>v0</p>']],
    ]);

    // Editor A advances the block to version 1.
    $this->putJson("/api/v1/admin/blocks/{$block->public_id}", [
        'content_i18n' => ['en' => ['html' => '<p>A</p>']],
        'expected_version' => 0,
    ])->assertOk()->assertJsonPath('data.lock_version', 1);

    // Editor B still believes it holds version 0 -> stale, exact contract, nothing changes.
    $this->putJson("/api/v1/admin/blocks/{$block->public_id}", [
        'content_i18n' => ['en' => ['html' => '<p>B</p>']],
        'expected_version' => 0,
    ])->assertStatus(409)
        ->assertExactJson(['error' => 'stale_write', 'current_version' => 1]);

    $fresh = $block->fresh();
    expect($fresh->lock_version)->toBe(1)
        ->and($fresh->content_i18n['en']['html'])->toBe('<p>A</p>');
});

it('stays backward compatible when expected_version is absent (last-write-wins)', function () {
    $block = Block::factory()->for($this->lesson)->create(['type' => 'article', 'position' => 0]);

    $this->putJson("/api/v1/admin/blocks/{$block->public_id}", [
        'content_i18n' => ['en' => ['html' => '<p>one</p>']],
    ])->assertOk()->assertJsonPath('data.lock_version', 1);

    // No version supplied again -> overwrite, not rejected.
    $this->putJson("/api/v1/admin/blocks/{$block->public_id}", [
        'content_i18n' => ['en' => ['html' => '<p>two</p>']],
    ])->assertOk()->assertJsonPath('data.lock_version', 2);

    expect($block->fresh()->content_i18n['en']['html'])->toBe('<p>two</p>');
});

it('reorders blocks within a lesson deterministically with contiguous positions', function () {
    $a = Block::factory()->for($this->lesson)->create(['type' => 'article', 'position' => 0]);
    $b = Block::factory()->for($this->lesson)->create(['type' => 'article', 'position' => 1]);
    $c = Block::factory()->for($this->lesson)->create(['type' => 'article', 'position' => 2]);

    // Lesson is the optimistic-lock unit; a successful reorder advances the lesson's lock_version.
    $this->putJson("/api/v1/admin/lessons/{$this->lesson->public_id}/blocks/order", [
        'order' => [$c->public_id, $a->public_id, $b->public_id],
        'expected_version' => 0,
    ])->assertOk()->assertJsonPath('data.lock_version', 1);

    expect($c->fresh()->position)->toBe(0)
        ->and($a->fresh()->position)->toBe(1)
        ->and($b->fresh()->position)->toBe(2)
        ->and($this->lesson->fresh()->lock_version)->toBe(1);

    // Positions are a clean contiguous 0..n-1 with no duplicates or gaps.
    $positions = Block::where('lesson_id', $this->lesson->id)->orderBy('position')->pluck('position')->all();
    expect($positions)->toBe([0, 1, 2]);
});

it('rejects a stale block reorder and preserves the first writer ordering', function () {
    $a = Block::factory()->for($this->lesson)->create(['type' => 'article', 'position' => 0]);
    $b = Block::factory()->for($this->lesson)->create(['type' => 'article', 'position' => 1]);

    // Editor A reorders to [B, A] from lesson version 0 -> lesson advances to version 1.
    $this->putJson("/api/v1/admin/lessons/{$this->lesson->public_id}/blocks/order", [
        'order' => [$b->public_id, $a->public_id],
        'expected_version' => 0,
    ])->assertOk()->assertJsonPath('data.lock_version', 1);

    // Editor B still holds version 0 -> stale reorder rejected, first order stands.
    $this->putJson("/api/v1/admin/lessons/{$this->lesson->public_id}/blocks/order", [
        'order' => [$a->public_id, $b->public_id],
        'expected_version' => 0,
    ])->assertStatus(409)
        ->assertExactJson(['error' => 'stale_write', 'current_version' => 1]);

    expect($b->fresh()->position)->toBe(0)
        ->and($a->fresh()->position)->toBe(1)
        ->and($this->lesson->fresh()->lock_version)->toBe(1);
});
