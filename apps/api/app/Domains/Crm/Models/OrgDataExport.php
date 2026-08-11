<?php

namespace App\Domains\Crm\Models;

use App\Domains\Crm\Enums\OrgExportStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An org BI/data export request + its produced artifact bundle.
 *
 * Isolation is by explicit organization_id (mirrors the Analytics ExportJob pattern rather than the
 * global tenant scope, because the signed download route runs WITHOUT an authenticated user — so there
 * is no ambient tenant to scope by, and every read confines on organization_id by hand instead).
 *
 * @property int $organization_id
 * @property string $public_id
 */
class OrgDataExport extends Model
{
    use HasPublicId;

    protected $fillable = [
        'organization_id', 'requested_by_user_id', 'dataset', 'status',
        'storage_prefix', 'manifest', 'row_count', 'completed_at',
    ];

    /** The storage prefix is an internal path and is never serialized. */
    protected $hidden = ['storage_prefix'];

    protected function casts(): array
    {
        return [
            'status' => OrgExportStatus::class,
            'manifest' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === OrgExportStatus::Completed;
    }
}
