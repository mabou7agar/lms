<?php

namespace App\Domains\Certification\Models;

use App\Domains\Certification\Database\Factories\BadgeFactory;
use App\Platform\Shared\Traits\HasPublicId;
use App\Platform\Shared\Traits\HasSlug;
use App\Platform\Shared\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    /** @use HasFactory<BadgeFactory> */
    use HasFactory;

    use HasPublicId;
    use HasSlug;
    use HasTranslations;

    protected $fillable = [
        'name', 'name_i18n', 'slug', 'description', 'description_i18n', 'icon_path', 'criteria',
        'criteria_i18n', 'is_active',
    ];

    /** @var array<int, string> */
    protected array $translatable = ['name_i18n', 'description_i18n', 'criteria_i18n'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'name_i18n' => 'array',
            'description_i18n' => 'array',
            'criteria_i18n' => 'array',
        ];
    }

    protected static function newFactory(): BadgeFactory
    {
        return BadgeFactory::new();
    }
}
