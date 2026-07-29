<?php

use App\Domains\Authoring\Enums\BlockFamily;
use App\Domains\Authoring\Enums\BlockType;
use App\Domains\Authoring\Enums\LessonType;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Services\BlockBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('is a no-op when the feature flag is off', function () {
    config()->set('authoring.blocks_enabled', false);
    Lesson::factory()->count(3)->create();

    expect((new BlockBackfillService)->run())->toBe(0)
        ->and(Block::count())->toBe(0);
});

it('creates one block per lesson with mapped type/family/payload/state when enabled', function () {
    config()->set('authoring.blocks_enabled', true);
    $lesson = Lesson::factory()->ofType(LessonType::Video)->create([
        'content' => ['k' => 'v'],
        'publish_state' => PublishState::Published->value,
    ]);

    $created = (new BlockBackfillService)->run();

    expect($created)->toBe(1);
    $block = Block::firstOrFail();
    expect($block->lesson_id)->toBe($lesson->id)
        ->and($block->type)->toBe(BlockType::Video)
        ->and($block->family)->toBe(BlockFamily::Media)
        ->and($block->payload)->toBe(['k' => 'v'])
        ->and($block->publish_state)->toBe(PublishState::Published)
        ->and($block->position)->toBe(0);
});

it('is idempotent across re-runs', function () {
    config()->set('authoring.blocks_enabled', true);
    Lesson::factory()->count(2)->create();
    $service = new BlockBackfillService;

    expect($service->run())->toBe(2);
    expect($service->run())->toBe(0)
        ->and(Block::count())->toBe(2);
});

it('does not recreate a block for a lesson whose block was soft-deleted', function () {
    config()->set('authoring.blocks_enabled', true);
    Lesson::factory()->create();
    $service = new BlockBackfillService;
    $service->run();

    Block::firstOrFail()->delete(); // soft delete

    expect($service->run())->toBe(0)
        ->and(Block::withTrashed()->count())->toBe(1);
});
