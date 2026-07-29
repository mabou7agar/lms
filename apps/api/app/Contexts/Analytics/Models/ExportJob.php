<?php

namespace App\Contexts\Analytics\Models;

use App\Contexts\Analytics\Enums\ExportFormat;
use App\Contexts\Analytics\Enums\ExportStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 */
class ExportJob extends Model
{
    use HasPublicId;

    protected $fillable = ['user_id', 'format', 'status', 'source', 'params', 'file_path', 'row_count', 'completed_at'];

    protected $hidden = ['file_path']; // storage path is never serialized

    protected function casts(): array
    {
        return [
            'format' => ExportFormat::class,
            'status' => ExportStatus::class,
            'params' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === ExportStatus::Completed;
    }
}
