<?php

namespace App\Domains\Authoring\Models;

use App\Domains\Authoring\Database\Factories\BlockFactory;
use App\Domains\Authoring\Enums\BlockFamily;
use App\Domains\Authoring\Enums\BlockType;
use App\Domains\Authoring\Enums\PublishState;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P2/W02 - A first-class typed content unit belonging to a Lesson. Promotes the block payloads the
 * frontend already stores inside `lessons.content` into queryable, reusable rows. Additive; the
 * feature is gated by the `authoring.blocks_enabled` flag (enforced in BlockBackfillService, the
 * only write path today) and wired into no existing read path yet.
 *
 * @property BlockType $type
 * @property BlockFamily $family
 * @property PublishState $publish_state
 * @property string $public_id
 * @property int $lesson_id
 * @property array<string, mixed>|null $payload
 * @property int $position
 * @property int|null $learning_object_id
 */
class Block extends Model
{
    /** @use HasFactory<BlockFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    protected $table = 'content_blocks';

    /**
     * Tightened surface: only presentation fields are mass-assignable. Server-controlled fields
     * (`lesson_id`, `publish_state`, `learning_object_id`) must be set explicitly by trusted code so
     * a future HTTP path cannot reassign a block across lessons, self-publish, or point at an
     * arbitrary learning object. `family` is intentionally excluded — it is derived from `type`.
     */
    protected $fillable = ['type', 'payload', 'position'];

    protected function casts(): array
    {
        return [
            'type' => BlockType::class,
            'family' => BlockFamily::class,
            'publish_state' => PublishState::class,
            'payload' => 'array',
            'position' => 'integer',
            'learning_object_id' => 'integer',
        ];
    }

    /**
     * Enforce the type -> family invariant inside the aggregate: family always matches the type's
     * family regardless of caller, so a Block can never persist with a mismatched family.
     */
    protected static function booted(): void
    {
        static::saving(function (Block $block): void {
            $type = $block->getAttribute('type');
            if ($type instanceof BlockType) {
                $block->family = $type->family();
            }
        });
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publish_state', PublishState::Published->value);
    }

    public function isPublished(): bool
    {
        return $this->publish_state === PublishState::Published;
    }

    protected static function newFactory(): BlockFactory
    {
        return BlockFactory::new();
    }
}
