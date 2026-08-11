<?php

namespace App\Platform\AI\Models;

use App\Platform\AI\Database\Factories\AiUsageFactory;
use App\Platform\AI\Enums\AiFeature;
use App\Platform\AI\Enums\AiProvider;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenant;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single append-only AI usage/cost record. Tenant-owned (organization_id) via BelongsToTenant, so
 * every read is automatically isolated to the active tenant; platform-wide aggregation runs under an
 * explicit tenancy bypass. Never updated after creation.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $organization_id
 * @property int|null $user_id
 * @property AiFeature $feature
 * @property AiProvider $provider
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $estimated_cost_micros
 * @property string $request_id
 * @property string|null $prompt_key
 * @property int|null $prompt_version
 */
class AiUsage extends Model
{
    /** @use HasFactory<AiUsageFactory> */
    use HasFactory;

    use BelongsToTenant;
    use HasPublicId;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'user_id',
        'feature',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'estimated_cost_micros',
        'request_id',
        'prompt_key',
        'prompt_version',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'feature' => AiFeature::class,
            'provider' => AiProvider::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost_micros' => 'integer',
            'prompt_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }

    protected static function newFactory(): AiUsageFactory
    {
        return AiUsageFactory::new();
    }
}
