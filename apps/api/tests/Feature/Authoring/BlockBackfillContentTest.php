<?php

use App\Domains\Authoring\Enums\BlockType;
use App\Domains\Authoring\Enums\LessonType;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Services\BlockBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('represents an existing lesson as a block while preserving lessons.content (runtime unchanged)', function () {
    config()->set('authoring.blocks_enabled', true);

    $lesson = Lesson::factory()->ofType(LessonType::Article)->create([
        'content' => ['html' => '<p>legacy body</p>'],
        'publish_state' => PublishState::Published->value,
    ]);

    $created = (new BlockBackfillService)->run();
    expect($created)->toBe(1);

    $block = Block::firstOrFail();

    // Legacy payload mirror + new localized surface both reflect the lesson content...
    expect($block->type)->toBe(BlockType::Article)
        ->and($block->payload)->toBe(['html' => '<p>legacy body</p>'])
        ->and($block->content_i18n)->toBe(['en' => ['html' => '<p>legacy body</p>']])
        ->and($block->publish_state)->toBe(PublishState::Published);

    // ...and the learner-facing lessons.content is NEVER destroyed or mutated.
    expect($lesson->fresh()->content)->toBe(['html' => '<p>legacy body</p>']);
});

it('leaves lessons.content intact for a lesson with no content', function () {
    config()->set('authoring.blocks_enabled', true);

    $lesson = Lesson::factory()->create(['content' => null]);

    (new BlockBackfillService)->run();

    $block = Block::firstOrFail();
    expect($block->content_i18n)->toBeNull()
        ->and($lesson->fresh()->content)->toBeNull();
});

it('is a no-op (and writes no blocks) when the feature flag is off', function () {
    config()->set('authoring.blocks_enabled', false);
    Lesson::factory()->count(2)->create(['content' => ['html' => 'x']]);

    expect((new BlockBackfillService)->run())->toBe(0)
        ->and(Block::count())->toBe(0);
});
