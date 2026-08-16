<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Database\Factories\CourseFactory;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Platform\Shared\Enums\Visibility;
use App\Platform\Shared\Search\SearchableText;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenantNullable;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasSeo;
use App\Platform\Shared\Traits\HasSlug;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Catalog course aggregate. Owns metadata, taxonomy links, visibility, featuring and publish
 * lifecycle. Curriculum (sections/lessons) belongs to Authoring — not here.
 *
 * @property string $title
 * @property CourseStatus $status
 * @property string|null $trailer_path
 * @property int|null $duration_minutes
 *
 * @property-read \App\Platform\Shared\Commerce\Data\PurchaseSummary|null $purchase_summary How the
 *   course is sold, stashed by CourseController from the Shared PurchaseSummaryPort so a listing
 *   resolves every row in one call. NOT a column and NOT a relation — Catalog never reads a Commerce
 *   model; only this DTO crosses.
 * @property-read list<\App\Platform\Identity\Contracts\Data\UserRef>|null $trainer_refs Boundary-safe
 *   trainer display refs, resolved via UserLookupPort and stashed by CourseController::attachTrainerRefs
 *   for the API listing resource. NOT a column and NOT an Eloquent relation — trainer ids live on the
 *   course_trainer pivot (CourseTrainer); the Catalog context never imports Identity's User model.
 */
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    // T1 Option-N tenancy: adds SharedOrOwnedTenantScope (global rows [organization_id IS NULL] OR the
    // active tenant's own rows) and stamps organization_id on create ONLY when a tenant is resolved
    // (else NULL = global). The tenant is derived server-side from the acting user's organization —
    // NEVER from client input. `organization_id` is intentionally NOT in $fillable, so a forged
    // organization_id/tenant_id in a request payload can never be mass-assigned onto a course.
    use BelongsToTenantNullable;

    use HasPublicId;
    use HasSeo;
    use HasSlug;
    use HasTranslations;
    use SearchableText;
    use SoftDeletes;

    protected $fillable = [
        'title', 'title_i18n', 'slug', 'subtitle', 'subtitle_i18n', 'description', 'description_i18n',
        'learning_objectives_i18n', 'requirements_i18n', 'target_audience_i18n', 'duration_minutes',
        'level_id', 'language_id', 'status', 'visibility', 'is_featured', 'thumbnail_path', 'trailer_path',
        'position', 'published_at', 'scheduled_publish_at', 'last_published_at', 'seo',
    ];

    /**
     * Localized attributes. title/subtitle/description hold prose; the marketing *_i18n columns hold
     * localized LISTS ({locale => [items...]}). All resolve through the same TranslationResolver, so a
     * list attribute yields the request-locale array (never the raw {en,ar} map) via localized().
     *
     * @var array<int, string>
     */
    protected array $translatable = [
        'title_i18n', 'subtitle_i18n', 'description_i18n',
        'learning_objectives_i18n', 'requirements_i18n', 'target_audience_i18n',
    ];

    /**
     * Base fields folded into the locale-aware `search_text` index by SearchableText (both the
     * legacy scalar and every locale of its `{base}_i18n` map are indexed).
     *
     * @var array<int, string>
     */
    protected array $searchable = ['title', 'subtitle', 'description'];

    protected function casts(): array
    {
        return [
            'status' => CourseStatus::class,
            'visibility' => Visibility::class,
            'is_featured' => 'boolean',
            'position' => 'integer',
            'published_at' => 'datetime',
            'scheduled_publish_at' => 'datetime',
            'last_published_at' => 'datetime',
            'seo' => 'array',
            'title_i18n' => 'array',
            'subtitle_i18n' => 'array',
            'description_i18n' => 'array',
            'learning_objectives_i18n' => 'array',
            'requirements_i18n' => 'array',
            'target_audience_i18n' => 'array',
            'duration_minutes' => 'integer',
        ];
    }

    /** Slug source is the title (overrides HasSlug default 'name'). */
    public function slugSource(): string
    {
        return 'title';
    }

    // ----- Relations -----

    /** @return BelongsTo<CourseLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(CourseLevel::class, 'level_id');
    }

    /** @return BelongsTo<CourseLanguage, $this> */
    public function language(): BelongsTo
    {
        return $this->belongsTo(CourseLanguage::class, 'language_id');
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'course_category');
    }

    /** @return BelongsToMany<CourseTag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CourseTag::class, 'course_tag', 'course_id', 'tag_id');
    }

    /** @return HasMany<CourseTrainer, $this> Pivot links to trainer user ids (no Identity model reference). */
    public function trainerLinks(): HasMany
    {
        return $this->hasMany(CourseTrainer::class, 'course_id');
    }

    /**
     * Ordered gallery images (U8). Each item carries a media asset reference (public_id) and a
     * position; deleting an item never touches the shared asset.
     *
     * @return HasMany<CourseGalleryItem, $this>
     */
    public function galleryItems(): HasMany
    {
        return $this->hasMany(CourseGalleryItem::class, 'course_id')->orderBy('position');
    }

    /**
     * Flat sync of the course_trainer pivot by trainer user id (preserves the prior
     * trainers()->sync($ids) behavior without a belongsToMany(User) relation).
     *
     * @param  array<int, int|string>  $userIds
     */
    public function syncTrainers(array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $existing = DB::table('course_trainer')->where('course_id', $this->id)
            ->pluck('user_id')->map(fn ($v): int => (int) $v)->all();

        if (($detach = array_diff($existing, $userIds)) !== []) {
            DB::table('course_trainer')->where('course_id', $this->id)->whereIn('user_id', $detach)->delete();
        }
        foreach (array_diff($userIds, $existing) as $userId) {
            DB::table('course_trainer')->insert(['course_id' => $this->id, 'user_id' => $userId]);
        }
    }

    // ----- Scopes -----

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', CourseStatus::Published->value);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('visibility', Visibility::Public->value);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scheduled courses whose publish time has arrived. The scheduler reads this each minute and
     * publishes each (still subject to the readiness guard) via the CourseLifecycle state machine.
     *
     * @param  Builder<Course>  $query
     * @return Builder<Course>
     */
    public function scopeScheduledDue(Builder $query): Builder
    {
        return $query->where('status', CourseStatus::Scheduled->value)
            ->whereNotNull('scheduled_publish_at')
            ->where('scheduled_publish_at', '<=', now());
    }

    /**
     * Courses trained by the given user id (via the course_trainer pivot).
     *
     * @param  Builder<Course>  $query
     * @return Builder<Course>
     */
    public function scopeForTrainer(Builder $query, int $userId): Builder
    {
        return $query->whereHas('trainerLinks', fn (Builder $t) => $t->where('user_id', $userId));
    }

    public function isPublished(): bool
    {
        return $this->status === CourseStatus::Published;
    }

    public function isArchived(): bool
    {
        return $this->status === CourseStatus::Archived;
    }

    /** True when the given user id trains (is linked to) this course. */
    public function isTrainedBy(int $userId): bool
    {
        return $this->trainerLinks()->where('user_id', $userId)->exists();
    }

    protected static function newFactory(): CourseFactory
    {
        return CourseFactory::new();
    }
}
