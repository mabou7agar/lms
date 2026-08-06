<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Database\Factories\CourseTagFactory;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasSlug;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CourseTag extends Model
{
    /** @use HasFactory<CourseTagFactory> */
    use HasFactory;

    use HasPublicId;
    use HasSlug;
    use HasTranslations;

    protected $fillable = ['name', 'name_i18n', 'slug'];

    /** @var array<int, string> */
    protected array $translatable = ['name_i18n'];

    protected function casts(): array
    {
        return ['name_i18n' => 'array'];
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_tag', 'tag_id', 'course_id');
    }

    protected static function newFactory(): CourseTagFactory
    {
        return CourseTagFactory::new();
    }
}
