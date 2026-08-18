<?php

namespace App\Platform\Notifications\Models;

use App\Platform\Notifications\Enums\AutomationActionType;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property AutomationActionType $action_type
 * @property string $category
 * @property mixed|null $config
 * @property string $template_key
 */
class AutomationAction extends Model
{
    use HasPublicId;

    protected $fillable = ['automation_rule_id', 'action_type', 'template_key', 'category', 'channels', 'config'];

    protected function casts(): array
    {
        return ['action_type' => AutomationActionType::class, 'channels' => 'array', 'config' => 'array'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
