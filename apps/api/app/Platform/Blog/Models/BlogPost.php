<?php

namespace App\Platform\Blog\Models;

use App\Platform\Blog\Database\Factories\BlogPostFactory;
use App\Platform\Blog\Enums\PostStatus;
use App\Platform\Identity\Contracts\Data\UserRef;
use App\Platform\Shared\Html\HtmlSanitizer;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A blog article. Addressed by a unique `slug`, gated by an editorial PostStatus plus an optional
 * published_at/unpublished_at schedule window, and optionally filed under one BlogCategory.
 * Bilingual title/excerpt/body/SEO are plain { en, ar } JSON bags (NOT the HasTranslations i18n
 * system — the frontend picks the locale via pickLocale); `body` HTML is sanitized on every write
 * via the shared HtmlSanitizer. `cover_image` stores a MediaAsset public_id reference (resolved to
 * a public URL by the API resource), never a brittle URL.
 *
 * On every update the post snapshots itself into blog_post_versions (version history) so any prior
 * state can be restored with rollbackTo().
 *
 * @property int $id
 * @property string $public_id
 * @property string $slug
 * @property array<string, string> $title
 * @property array<string, string>|null $excerpt
 * @property array<string, string> $body
 * @property string|null $cover_image
 * @property int|null $blog_category_id
 * @property PostStatus $status
 * @property Carbon|null $published_at
 * @property Carbon|null $unpublished_at
 * @property bool $is_featured
 * @property int|null $reading_minutes
 * @property array<string, mixed>|null $seo
 * @property int|null $author_id
 * @property int|null $reviewer_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read UserRef|null $author_ref Boundary-safe author
 *   display ref, resolved via UserLookupPort and stashed by the controller for the API resources.
 *   NOT a column and NOT an Eloquent relation — author_id / reviewer_id are plain integer FKs; the
 *   Blog context never imports Identity's User model (cross-context boundary).
 */
class BlogPost extends Model
{
    /** @use HasFactory<BlogPostFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'slug', 'title', 'excerpt', 'body', 'cover_image', 'blog_category_id', 'status',
        'published_at', 'unpublished_at', 'is_featured', 'reading_minutes', 'seo',
        'author_id', 'reviewer_id',
    ];

    /** Fields captured in a version snapshot (everything an editor can change). */
    private const VERSIONED_FIELDS = [
        'slug', 'title', 'excerpt', 'body', 'cover_image', 'blog_category_id', 'status',
        'published_at', 'unpublished_at', 'is_featured', 'reading_minutes', 'seo', 'reviewer_id',
    ];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'excerpt' => 'array',
            'body' => 'array',
            'status' => PostStatus::class,
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
            'is_featured' => 'boolean',
            'reading_minutes' => 'integer',
            'seo' => 'array',
        ];
    }

    protected static function newFactory(): BlogPostFactory
    {
        return BlogPostFactory::new();
    }

    protected static function booted(): void
    {
        // Sanitize both body locales on every write — the single write-time sanitization point for
        // post HTML (Filament, the API Action, and the seeder all pass through here).
        static::saving(function (BlogPost $post): void {
            $post->sanitizeBody();
        });

        // Snapshot the post into its version history after each update (incl. rollback restores).
        static::updated(function (BlogPost $post): void {
            $post->recordVersion();
        });
    }

    /** @return HasMany<BlogPostVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(BlogPostVersion::class, 'blog_post_id')->orderByDesc('version');
    }

    /** @return BelongsTo<BlogCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    /**
     * Live posts only: Published status, past/absent published_at, and not yet unpublished.
     *
     * @param  Builder<BlogPost>  $query
     * @return Builder<BlogPost>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', PostStatus::Published->value)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('unpublished_at')->orWhere('unpublished_at', '>', now()));
    }

    /** Whether THIS instance is currently live (mirrors the published() scope). */
    public function isLive(): bool
    {
        if ($this->status !== PostStatus::Published) {
            return false;
        }

        if ($this->published_at !== null && $this->published_at->isFuture()) {
            return false;
        }

        if ($this->unpublished_at !== null && ! $this->unpublished_at->isFuture()) {
            return false;
        }

        return true;
    }

    /** Publish now: set status Published and stamp published_at (clearing any prior unpublish). */
    public function publish(): static
    {
        $this->forceFill([
            'status' => PostStatus::Published,
            'published_at' => $this->published_at ?? now(),
            'unpublished_at' => null,
        ])->save();

        return $this;
    }

    /**
     * Snapshot the current post fields into blog_post_versions as the next sequential version.
     * Append-only; never overwrites an existing version row.
     */
    public function recordVersion(): BlogPostVersion
    {
        $next = (int) $this->versions()->max('version') + 1;

        $authId = auth()->id();

        return $this->versions()->create([
            'version' => $next,
            'snapshot' => $this->versionSnapshot(),
            'author_id' => $authId !== null ? (int) $authId : $this->author_id,
            'created_at' => now(),
        ]);
    }

    /**
     * Restore the fields from a prior version snapshot. Saving triggers a fresh version record, so
     * a rollback is itself captured in history (history is never rewritten).
     */
    public function rollbackTo(int $version): static
    {
        $snapshot = $this->versions()->where('version', $version)->firstOrFail();

        /** @var array<string, mixed> $data */
        $data = $snapshot->snapshot;

        $this->fill(array_intersect_key($data, array_flip(self::VERSIONED_FIELDS)));
        $this->save();

        return $this;
    }

    /**
     * The subset of fields captured in a version snapshot.
     *
     * @return array<string, mixed>
     */
    private function versionSnapshot(): array
    {
        $out = [];
        foreach (self::VERSIONED_FIELDS as $field) {
            $value = $this->getAttribute($field);
            $out[$field] = $value instanceof \BackedEnum ? $value->value : ($value instanceof Carbon ? $value->toIso8601String() : $value);
        }

        return $out;
    }

    /** Sanitize the body HTML for every locale in-place. */
    private function sanitizeBody(): void
    {
        $body = $this->getAttribute('body');

        if (! is_array($body)) {
            return;
        }

        $sanitizer = app(HtmlSanitizer::class);

        foreach ($body as $locale => $html) {
            if (is_string($html)) {
                $body[$locale] = $sanitizer->sanitize($html);
            }
        }

        $this->setAttribute('body', $body);
    }
}
