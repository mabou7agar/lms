<?php

declare(strict_types=1);

namespace App\Domains\Forum\Models;

use App\Domains\Forum\Database\Factories\ForumThreadFactory;
use App\Domains\Forum\Tenancy\InheritsCourseTenancy;
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
 * A course-level discussion thread.
 *
 * `course_id` is the authorization + tenancy anchor: the thread inherits the owning course's T1
 * "global-OR-own-org" tenancy transitively via {@see InheritsCourseTenancy} (a join on `courses`, no
 * local tenant column consulted). `organization_id` is a server-stamped denormalisation and is NOT
 * fillable — CreateThreadAction writes it from the course. `body` is sanitized on write.
 *
 * @property int $id
 * @property string $public_id
 * @property int $course_id
 * @property int $user_id
 * @property int|null $organization_id
 * @property string $title
 * @property string $body
 * @property Carbon|null $pinned_at
 * @property Carbon|null $locked_at
 * @property int|null $solved_post_id
 * @property int $posts_count
 * @property Carbon|null $last_post_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ForumPost> $posts
 * @property-read ForumPost|null $solvedPost
 */
class ForumThread extends Model
{
    use CanBeReported;

    /** @use HasFactory<ForumThreadFactory> */
    use HasFactory;

    use HasPublicId;
    // T1 Option-N tenancy inherited from the owning Course (forum_threads.course_id). No tenant
    // column is consulted: CourseTenantScope filters to threads whose course is global or owned by
    // the active tenant, and dormant when no tenant is resolved.
    use InheritsCourseTenancy;

    use SoftDeletes;

    /**
     * `user_id`, `organization_id`, moderation flags (pinned_at/locked_at/solved_post_id) and the
     * denormalised counters are all managed server-side by the Forum actions — never mass-assigned.
     *
     * @var list<string>
     */
    protected $fillable = ['course_id', 'title', 'body'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pinned_at' => 'datetime',
            'locked_at' => 'datetime',
            'last_post_at' => 'datetime',
            'posts_count' => 'integer',
            'solved_post_id' => 'integer',
        ];
    }

    /**
     * Sanitize the thread body on write through the single shared HtmlSanitizer (scripts, iframes,
     * event handlers and javascript: URLs are stripped). Never sanitize with regex.
     */
    protected function body(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => app(HtmlSanitizer::class)->sanitize($value),
        );
    }

    /** @return HasMany<ForumPost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class, 'thread_id');
    }

    /** The accepted answer post (solved_post_id), if any. Manual belongsTo — no FK on the column. */
    public function solvedPost(): BelongsTo
    {
        return $this->belongsTo(ForumPost::class, 'solved_post_id');
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function isSolved(): bool
    {
        return $this->solved_post_id !== null;
    }

    public function courseId(): int
    {
        return (int) $this->getAttribute('course_id');
    }

    protected static function newFactory(): ForumThreadFactory
    {
        return ForumThreadFactory::new();
    }
}
