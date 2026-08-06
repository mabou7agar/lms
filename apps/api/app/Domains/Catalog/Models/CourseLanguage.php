<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Database\Factories\CourseLanguageFactory;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseLanguage extends Model
{
    /** @use HasFactory<CourseLanguageFactory> */
    use HasFactory;

    use HasPublicId;
    use HasTranslations;

    protected $fillable = ['code', 'name', 'name_i18n', 'position'];

    /** @var array<int, string> */
    protected array $translatable = ['name_i18n'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'name_i18n' => 'array',
        ];
    }

    protected static function newFactory(): CourseLanguageFactory
    {
        return CourseLanguageFactory::new();
    }
}
