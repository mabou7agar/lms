<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Database\Factories\CourseLevelFactory;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasSlug;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseLevel extends Model
{
    /** @use HasFactory<CourseLevelFactory> */
    use HasFactory;

    use HasPublicId;
    use HasSlug;
    use HasTranslations;

    protected $fillable = ['name', 'name_i18n', 'slug', 'position'];

    /** @var array<int, string> */
    protected array $translatable = ['name_i18n'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'name_i18n' => 'array',
        ];
    }

    protected static function newFactory(): CourseLevelFactory
    {
        return CourseLevelFactory::new();
    }
}
