<?php

use App\Domains\Authoring\Enums\BlockFamily;
use App\Domains\Authoring\Enums\BlockType;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts enums + payload and derives family from type', function () {
    $block = Block::factory()->ofType(BlockType::Video)->create(['payload' => ['src' => 'x']]);

    $fresh = $block->fresh();

    expect($fresh->type)->toBe(BlockType::Video)
        ->and($fresh->family)->toBe(BlockFamily::Media)
        ->and($fresh->payload)->toBe(['src' => 'x'])
        ->and($fresh->publish_state)->toBe(PublishState::Draft)
        ->and($fresh->public_id)->not->toBeNull();
});

it('keeps family consistent with type even when a wrong family is assigned', function () {
    $block = Block::factory()->ofType(BlockType::Quiz)->create();

    $block->family = BlockFamily::Content; // attempt to desync
    $block->save();

    expect($block->fresh()->family)->toBe(BlockFamily::Interactive);
});

it('does not mass-assign guarded fields (lesson_id, publish_state, learning_object_id)', function () {
    $lesson = Lesson::factory()->create();

    $block = new Block;
    $block->fill([
        'type' => BlockType::Article->value,
        'position' => 0,
        'lesson_id' => $lesson->id,
        'publish_state' => 'published',
        'learning_object_id' => 999,
    ]);

    expect($block->lesson_id)->toBeNull()
        ->and($block->getAttribute('publish_state'))->toBeNull()
        ->and($block->learning_object_id)->toBeNull()
        ->and($block->type)->toBe(BlockType::Article);
});

it('scopePublished filters and isPublished reflects state', function () {
    $lesson = Lesson::factory()->create();
    Block::factory()->for($lesson)->create(['position' => 0]);               // draft
    $published = Block::factory()->for($lesson)->published()->create(['position' => 1]);

    expect(Block::published()->count())->toBe(1)
        ->and($published->isPublished())->toBeTrue();
});

it('soft deletes', function () {
    $block = Block::factory()->create();

    $block->delete();

    expect(Block::count())->toBe(0)
        ->and(Block::withTrashed()->count())->toBe(1);
});
