<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Database\Factories\ProductFactory;
use App\Contexts\Commerce\Enums\ProductStatus;
use App\Contexts\Commerce\Enums\ProductType;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasSlug;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A purchasable product. Grants one or more courses on purchase (single course or bundle).
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasPublicId;
    use HasSlug;
    use HasTranslations;
    use SoftDeletes;

    protected $fillable = ['type', 'title', 'title_i18n', 'slug', 'description', 'description_i18n', 'image_path', 'status'];

    /** @var array<int, string> */
    protected array $translatable = ['title_i18n', 'description_i18n'];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => ProductStatus::class,
            'title_i18n' => 'array',
            'description_i18n' => 'array',
        ];
    }

    public function slugSource(): string
    {
        return 'title';
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'product_courses');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === ProductStatus::Active;
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
