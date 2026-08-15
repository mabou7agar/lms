<?php

namespace App\Platform\Blog\Models;

use App\Platform\Blog\Database\Factories\BlogCategoryFactory;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A blog taxonomy category (Insights / Guides / News …). Addressed by a unique `slug`, ordered by
 * `position`. Bilingual name/description are plain { en, ar } JSON bags (NOT the HasTranslations
 * i18n system — the frontend picks the locale via pickLocale). A post belongs to at most one
 * category; deleting a category nulls its posts' category (nullOnDelete).
 *
 * @property int $id
 * @property string $public_id
 * @property string $slug
 * @property array<string, string> $name
 * @property array<string, string>|null $description
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BlogCategory extends Model
{
    /** @use HasFactory<BlogCategoryFactory> */
    use HasFactory;

    use HasPublicId;

    protected $fillable = ['slug', 'name', 'description', 'position'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'position' => 'integer',
        ];
    }

    protected static function newFactory(): BlogCategoryFactory
    {
        return BlogCategoryFactory::new();
    }

    /** @return HasMany<BlogPost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'blog_category_id');
    }
}
