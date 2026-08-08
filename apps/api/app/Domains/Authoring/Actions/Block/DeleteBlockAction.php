<?php

namespace App\Domains\Authoring\Actions\Block;

use App\Domains\Authoring\Models\Block;
use App\Platform\Shared\Actions\BaseAction;

/**
 * C5 - Soft-delete a block. The row is locked for the delete so it cannot race a concurrent edit,
 * and soft deletion (SoftDeletes) frees its (lesson_id, position) slot in the partial-unique index
 * while preserving history and snapshot restorability.
 */
class DeleteBlockAction extends BaseAction
{
    public function execute(Block $block): void
    {
        $this->transaction(function () use ($block): void {
            Block::query()->whereKey($block->getKey())->lockForUpdate()->firstOrFail()->delete();
        });
    }
}
