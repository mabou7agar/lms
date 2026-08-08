<?php

namespace App\Domains\Authoring\Actions\Block;

use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Block;
use App\Platform\Shared\Actions\BaseAction;

/**
 * C5 - Flip a block between Draft and Published. Mirrors SetLessonPublishStateAction: publish state
 * is server-controlled (forceFill), so it can only be changed through this action, never via the
 * mass-assignable content surface. The saving hook keeps family consistent (type is unchanged here).
 */
class SetBlockPublishStateAction extends BaseAction
{
    public function execute(Block $block, PublishState $state): Block
    {
        return $this->transaction(function () use ($block, $state): Block {
            $block->forceFill(['publish_state' => $state->value])->save();

            return $block;
        });
    }
}
