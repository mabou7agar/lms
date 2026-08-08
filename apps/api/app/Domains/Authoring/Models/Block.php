<?php

namespace App\Domains\Authoring\Models;

use App\Domains\Authoring\Database\Factories\BlockFactory;
use App\Domains\Authoring\Enums\BlockFamily;
use App\Domains\Authoring\Enums\BlockType;
use App\Domains\Authoring\Enums\PublishState;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P2/W02 + C5 - A first-class typed content unit belonging to a Lesson: the ordered block layer
 * INSIDE a lesson. Promotes the block payloads the frontend used to store inside `lessons.content`
 * into queryable, reusable, individually-versioned rows. Additive; the operable API + backfill are
 * gated by the `authoring.blocks_enabled` flag, so the learner runtime stays backward compatible
 * and legacy `lessons.content` is never touched.
 *
 * `content_i18n` is the localized (en/ar), typed-per-BlockType payload — the editing surface. The
 * legacy `payload` column is retained as the default-locale mirror for the backfill/snapshot readers
 * that predate localization. `lock_version` mirrors the curriculum C3 optimistic-lock counter.
 *
 * @property BlockType $type
 * @property BlockFamily $family
 * @property PublishState $publish_state
 * @property string $public_id
 * @property int $lesson_id
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $content_i18n localized typed payload map ({ en:{...}, ar:{...} })
 * @property array<string, mixed>|null $config
 * @property int $position
 * @property int $lock_version optimistic-locking counter (C3); advanced on every guarded write
 * @property int|null $learning_object_id
 * @property int|null $created_by authoring attribution (users.id); decoupled, no FK
 */
class Block extends Model
{
    /** @use HasFactory<BlockFactory> */
    use HasFactory;

    use HasPublicId;
    use HasTranslations;
    use SoftDeletes;

    protected $table = 'content_blocks';

    /**
     * Tightened surface: only presentation/content fields are mass-assignable. Server-controlled
     * fields (`lesson_id`, `publish_state`, `learning_object_id`, `created_by`, `lock_version`) must
     * be set explicitly by trusted code so a future/HTTP path cannot reassign a block across lessons,
     * self-publish, forge attribution, or fake a lock version. `family` is intentionally excluded —
     * it is derived from `type`.
     */
    protected $fillable = ['type', 'payload', 'content_i18n', 'config', 'position'];

    /** @var array<int, string> */
    protected array $translatable = ['content_i18n'];

    protected function casts(): array
    {
        return [
            'type' => BlockType::class,
            'family' => BlockFamily::class,
            'publish_state' => PublishState::class,
            'payload' => 'array',
            'content_i18n' => 'array',
            'config' => 'array',
            'position' => 'integer',
            'lock_version' => 'integer',
            'learning_object_id' => 'integer',
            'created_by' => 'integer',
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
