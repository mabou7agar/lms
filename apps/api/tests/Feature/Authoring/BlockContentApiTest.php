<?php

use App\Domains\Authoring\Enums\PublishState;
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
    $this->admin = $admin;

    // C5 - the operable block layer is dormant until the flag is on.
    config()->set('authoring.blocks_enabled', true);

    $course = Course::factory()->create();
    $this->section = Section::factory()->create(['course_id' => $course->id]);
    $this->lesson = Lesson::factory()->create(['section_id' => $this->section->id]);
});

it('creates ordered blocks appended within the lesson', function () {
    $first = $this->postJson("/api/v1/admin/lessons/{$this->lesson->public_id}/blocks", [
        'type' => 'article',
        'content_i18n' => ['en' => ['html' => '<p>One</p>']],
    ])->assertCreated()
        ->assertJsonPath('data.type', 'article')
        ->assertJsonPath('data.family', 'content')
        ->assertJsonPath('data.publish_state', 'draft')
        ->assertJsonPath('data.position', 0)
        ->assertJsonPath('data.lock_version', 0);

    $second = $this->postJson("/api/v1/admin/lessons/{$this->lesson->public_id}/blocks", [
        'type' => 'external_link',
        'content_i18n' => ['en' => ['url' => 'https://example.com', 'label' => 'Docs']],
    ])->assertCreated()->assertJsonPath('data.position', 1);

    expect($first->json('data.id'))->not->toBe($second->json('data.id'));

    $this->getJson("/api/v1/admin/lessons/{$this->lesson->public_id}/blocks")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.position', 0)
        ->assertJsonPath('data.1.position', 1);
});

it('stores bilingual EN/AR content and resolves per requested locale', function () {
    $id = $this->postJson("/api/v1/admin/lessons/{$this->lesson->public_id}/blocks", [
        'type' => 'article',
        'content_i18n' => [
            'en' => ['html' => '<p>Hello</p>'],
            'ar' => ['html' => '<p>مرحبا</p>'],
        ],
    ])->assertCreated()->json('data.id');

    // Default locale resolves to EN...
    $this->getJson("/api/v1/admin/blocks/{$id}")
        ->assertOk()
        ->assertJsonPath('data.content.html', '<p>Hello</p>')
        ->assertJsonPath('data.content_i18n.ar.html', '<p>مرحبا</p>');

    // ...and ?locale=ar resolves the Arabic payload.
    $this->getJson("/api/v1/admin/blocks/{$id}?locale=ar")
        ->assertOk()
        ->assertJsonPath('data.content.html', '<p>مرحبا</p>');
});

it('edits a block, bumping its lock_version', function () {
    $id = $this->postJson("/api/v1/admin/lessons/{$this->lesson->public_id}/blocks", [
        'type' => 'article',
        'content_i18n' => ['en' => ['html' => '<p>v1</p>']],
    ])->assertCreated()->json('data.id');

    $this->putJson("/api/v1/admin/blocks/{$id}", [
        'content_i18n' => ['en' => ['html' => '<p>v2</p>']],
        'config' => ['collapsed' => true],
        'expected_version' => 0,
    ])->assertOk()
        ->assertJsonPath('data.lock_version', 1)
        ->assertJsonPath('data.content.html', '<p>v2</p>')
        ->assertJsonPath('data.config.collapsed', true);
});

it('duplicates a block within the lesson, appended and reset to draft', function () {
    $source = Block::factory()->published()->for($this->lesson)->create([
        'type' => 'article',
        'position' => 0,
        'content_i18n' => ['en' => ['html' => '<p>orig</p>']],
    ]);

    $newId = $this->postJson("/api/v1/admin/lessons/{$this->lesson->public_id}/blocks/{$source->public_id}/duplicate")
        ->assertCreated()
        ->assertJsonPath('data.publish_state', 'draft')
        ->assertJsonPath('data.position', 1)
        ->assertJsonPath('data.content.html', '<p>orig</p>')
        ->json('data.id');

    expect($newId)->not->toBe($source->public_id);
    // Source is untouched — still published.
    expect($source->fresh()->publish_state)->toBe(PublishState::Published);
});

it('publishes and unpublishes a block', function () {
    $block = Block::factory()->for($this->lesson)->create(['type' => 'article', 'position' => 0]);

    $this->postJson("/api/v1/admin/blocks/{$block->public_id}/publish", ['state' => 'published'])
        ->assertOk()->assertJsonPath('data.publish_state', 'published');
    expect($block->fresh()->publish_state)->toBe(PublishState::Published);

    $this->postJson("/api/v1/admin/blocks/{$block->public_id}/publish", ['state' => 'draft'])
        ->assertOk()->assertJsonPath('data.publish_state', 'draft');
    expect($block->fresh()->publish_state)->toBe(PublishState::Draft);
});

it('soft-deletes a block', function () {
    $block = Block::factory()->for($this->lesson)->create(['type' => 'article', 'position' => 0]);

    $this->deleteJson("/api/v1/admin/blocks/{$block->public_id}")->assertOk();

    expect(Block::withTrashed()->find($block->id)->trashed())->toBeTrue();
});

it('rejects an unsupported block type (not runtime-rendered)', function () {
    // scorm/assignment/etc. are design-complete on the frontend but not runtime-supported.
    $this->postJson("/api/v1/admin/lessons/{$this->lesson->public_id}/blocks", [
        'type' => 'scorm',
        'content_i18n' => ['en' => ['package_key' => 'x']],
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

    expect(Block::count())->toBe(0);
});

it('rejects an unknown payload field for the block type (no arbitrary blob)', function () {
    $this->postJson("/api/v1/admin/lessons/{$this->lesson->public_id}/blocks", [
        'type' => 'external_link',
        'content_i18n' => ['en' => ['url' => 'https://x.test', 'evil' => 'nope']],
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    expect(Block::count())->toBe(0);
});

it('rejects a payload under an unsupported authoring locale', function () {
    $this->postJson("/api/v1/admin/lessons/{$this->lesson->public_id}/blocks", [
        'type' => 'article',
        'content_i18n' => ['fr' => ['html' => '<p>Bonjour</p>']],
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('404s the entire block layer when the feature flag is off', function () {
    config()->set('authoring.blocks_enabled', false);

    $this->getJson("/api/v1/admin/lessons/{$this->lesson->public_id}/blocks")->assertNotFound();
    $this->postJson("/api/v1/admin/lessons/{$this->lesson->public_id}/blocks", [
        'type' => 'article',
        'content_i18n' => ['en' => ['html' => '<p>x</p>']],
    ])->assertNotFound();

    expect(Block::count())->toBe(0);
});
