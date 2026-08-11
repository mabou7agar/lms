<?php

namespace App\Platform\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The fires-once ledger for the automation engine. A unique (rule, subject, event) row is created on
 * first fire; a redispatched event resolves to the existing row and does nothing.
 *
 * Not tenant-scoped as a global query concern — organization_id is stored for reporting and the
 * runner always writes it explicitly from the acting tenant.
 *
 * @property int $id
 * @property int|null $organization_id
 * @property int $automation_rule_id
 * @property string $subject_key
 * @property string $event_key
 * @property Carbon $fired_at
 */
class AutomationRun extends Model
{
    protected $fillable = ['organization_id', 'automation_rule_id', 'subject_key', 'event_key', 'fired_at'];

    protected function casts(): array
    {
        return ['fired_at' => 'datetime'];
    }

    /** @return BelongsTo<AutomationRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
