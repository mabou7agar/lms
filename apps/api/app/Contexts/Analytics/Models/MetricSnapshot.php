<?php

namespace App\Contexts\Analytics\Models;

use App\Contexts\Analytics\Database\Factories\MetricSnapshotFactory;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenantNullable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The analytics read model. Analytics reads this exclusively — never operational tables.
 *
 * T1 tenant dimension (Option N): uses BelongsToTenantNullable, so a resolved tenant reads GLOBAL
 * (organization_id IS NULL) plus its OWN org's buckets — never another org's — and org buckets are
 * stamped on create when a tenant is active. With no resolved tenant the scope no-ops and the write
 * path records a global (NULL) row, so the pre-tenancy behaviour is byte-for-byte preserved.
 *
 * @property mixed $enrollments
 * @property mixed $label
 */
class MetricSnapshot extends Model
{
    use BelongsToTenantNullable;

    /** @use HasFactory<MetricSnapshotFactory> */
    use HasFactory;

    protected $fillable = ['organization_id', 'metric_key', 'granularity', 'period', 'dimension_key', 'dimension_value', 'value'];

    protected function casts(): array
    {
        return ['period' => 'date', 'value' => 'integer'];
    }

    protected static function newFactory(): MetricSnapshotFactory
    {
        return MetricSnapshotFactory::new();
    }
}
