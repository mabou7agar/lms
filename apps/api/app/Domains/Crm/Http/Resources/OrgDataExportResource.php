<?php

namespace App\Domains\Crm\Http\Resources;

use App\Domains\Crm\Models\OrgDataExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrgDataExport
 *
 * @property mixed $completed_at
 * @property mixed $created_at
 * @property mixed $dataset
 * @property mixed $manifest
 * @property mixed $row_count
 * @property mixed $status
 */
class OrgDataExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'dataset' => $this->dataset,
            'status' => $this->status->value,
            'row_count' => $this->row_count,
            'manifest' => $this->manifest,
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
