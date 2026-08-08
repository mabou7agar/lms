<?php

namespace App\Domains\Authoring\Actions\Block;

use App\Domains\Authoring\Actions\Block\Concerns\NormalizesBlockContent;
use App\Domains\Authoring\Enums\BlockType;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Html\HtmlSanitizer;

/**
 * C5 - Append a new block to the end of a lesson's ordered block layer, in Draft.
 *
 * The parent LESSON row is locked for the compare-and-append window (mirroring how the lesson
 * reorder/duplicate actions lock the parent SECTION): Postgres cannot lock an aggregate (max
 * position), so serializing concurrent appends via the parent row keeps positions deterministic and
 * collision-free under the partial-unique (lesson_id, position) index. A new block is never
 * published on creation.
 */
class CreateBlockAction extends BaseAction
{
    use NormalizesBlockContent;

    public function __construct(private readonly HtmlSanitizer $htmlSanitizer) {}

    protected function sanitizer(): HtmlSanitizer
    {
        return $this->htmlSanitizer;
    }

    /**
     * @param  array<string, mixed>  $data  validated CreateBlockRequest payload
     */
    public function execute(Lesson $lesson, array $data, ?int $createdBy = null): Block
    {
        $type = BlockType::from($data['type']);
        $content = $this->sanitizeContent($data['content_i18n'] ?? null);

        return $this->transaction(function () use ($lesson, $type, $data, $content, $createdBy): Block {
            Lesson::query()->whereKey($lesson->getKey())->lockForUpdate()->firstOrFail();

            $max = Block::where('lesson_id', $lesson->getKey())->max('position');
            $position = $max === null ? 0 : (int) $max + 1;

            $block = new Block([
                'type' => $type->value,
                'content_i18n' => $content,
                'config' => $data['config'] ?? null,
                'position' => $position,
            ]);
            $block->lesson_id = (int) $lesson->getKey();
            $block->publish_state = PublishState::Draft->value;
            $block->created_by = $createdBy;
            // Keep the legacy default-locale mirror populated for backfill/snapshot readers.
            $block->payload = $this->defaultLocaleContent($content);
            $block->save(); // saving hook derives family from type

            return $block;
        });
    }
}
