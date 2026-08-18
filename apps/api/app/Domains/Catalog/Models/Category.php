<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Database\Factories\CategoryFactory;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenantNullable;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasSeo;
use App\Platform\Shared\Traits\HasSlug;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Nested category. A category may have a parent and many children (self-referential tree).
 *
 * @property string|null $image_path
 * @property bool $is_active
 */
class Category extends Model
{
    // T1 Option-N tenancy (matrix: categories are SHARED-OR-OWNED/NULLABLE). Global taxonomy by default
    // (organization_id NULL); optional org-private categories when non-null. organization_id is
    // intentionally NOT in $fillable, so it can never be mass-assigned from a request payload.
    use BelongsToTenantNullable;

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use HasPublicId;
    use HasSeo;
    use HasSlug;
    use HasTranslations;
    use SoftDeletes;

    protected $fillable = [
        'parent_id', 'name', 'name_i18n', 'slug', 'description', 'description_i18n', 'image_path', 'icon', 'position', 'is_active', 'seo',
    ];

    /** @var array<int, string> */
    protected array $translatable = ['name_i18n', 'description_i18n'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
            'seo' => 'array',
            'name_i18n' => 'array',
            'description_i18n' => 'array',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_category');
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}
