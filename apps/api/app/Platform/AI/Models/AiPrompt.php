<?php

namespace App\Platform\AI\Models;

use App\Platform\AI\Database\Factories\AiPromptFactory;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable version of a library prompt. The active row for a (key, locale) is what
 * PromptLibrary resolves; duplication mints a new version, rollback flips which version is active.
 *
 * @property int $id
 * @property string $public_id
 * @property string $key
 * @property string|null $purpose
 * @property int $version
 * @property string|null $system_prompt
 * @property string $user_template
 * @property array<string, mixed>|null $variables
 * @property string|null $model_preference
 * @property string $locale
 * @property bool $active
 * @property int|null $created_by
 */
class AiPrompt extends Model
{
    /** @use HasFactory<AiPromptFactory> */
    use HasFactory;

    use HasPublicId;

    protected $fillable = [
        'key',
        'purpose',
        'version',
        'system_prompt',
        'user_template',
        'variables',
        'model_preference',
        'locale',
        'active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'version' => 'integer',
            'active' => 'boolean',
        ];
    }

    protected static function newFactory(): AiPromptFactory
    {
        return AiPromptFactory::new();
    }
}
