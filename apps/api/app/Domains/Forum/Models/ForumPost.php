<?php

declare(strict_types=1);

namespace App\Domains\Forum\Models;

use App\Domains\Forum\Database\Factories\ForumPostFactory;
use App\Platform\Shared\Html\HtmlSanitizer;
use App\Platform\Shared\Moderation\Concerns\CanBeReported;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A reply inside a forum thread. Nesting is capped at ONE level: a post whose `parent_post_id` is
 * non-null is a reply to a TOP-LEVEL post and can itself never be replied to (ReplyToThreadAction
 * enforces the depth). `is_instructor` is a server-stamped badge (derived via CourseAccessPort at
 * create) and is NOT fillable. Tenancy is inherited transitively from the parent thread.
 *
 * @property int $id
 * @property string $public_id
 * @property int $thread_id
 * @property int $user_id
 * @property int|null $parent_post_id
 * @property string $body
 * @property bool $is_instructor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read ForumThread $thread
 * @property-read ForumPost|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ForumPost> $replies
 */
class ForumPost extends Model
{
    use CanBeReported;

    /** @use HasFactory<ForumPostFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    /**
     * `user_id` and `is_instructor` are stamped server-side by ReplyToThreadAction — never
     * mass-assigned. `thread_id` / `parent_post_id` are set from server-resolved models.
     *
     * @var list<string>
     */
    protected $fillable = ['thread_id', 'parent_post_id', 'body'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_instructor' => 'boolean',
            'parent_post_id' => 'integer',
            'thread_id' => 'integer',
        ];
    }

    /**
     * Sanitize the post body on write through the single shared HtmlSanitizer. Never sanitize with
     * regex.
     */
    protected function body(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => app(HtmlSanitizer::class)->sanitize($value),
        );
    }

    /** @return BelongsTo<ForumThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ForumThread::class, 'thread_id');
    }

    /** @return BelongsTo<ForumPost, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ForumPost::class, 'parent_post_id');
    }

    /** @return HasMany<ForumPost, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(ForumPost::class, 'parent_post_id');
    }

    /** True when this post is a top-level post (the only kind that may receive a reply). */
    public function isTopLevel(): bool
    {
        return $this->parent_post_id === null;
    }

    protected static function newFactory(): ForumPostFactory
    {
        return ForumPostFactory::new();
    }
}
