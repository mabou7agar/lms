<?php

namespace App\Domains\Crm\Actions\Opportunity;

use App\Domains\Crm\Enums\ActivityType;
use App\Domains\Crm\Enums\OpportunityStatus;
use App\Domains\Crm\Exceptions\InvalidStageException;
use App\Domains\Crm\Models\Opportunity;
use App\Domains\Crm\Models\Stage;
use App\Domains\Crm\Services\ActivityLogger;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Moves an opportunity to a stage of its own pipeline. A won/lost stage also closes the deal
 * (status + timestamps + probability) so the pipeline and the opportunity status never drift.
 */
class MoveOpportunityStageAction extends BaseAction
{
    public function __construct(private readonly ActivityLogger $log) {}

    public function execute(Opportunity $opportunity, Stage $stage): Opportunity
    {
        if ($opportunity->pipeline_id !== null && $stage->pipeline_id !== $opportunity->pipeline_id) {
            throw new InvalidStageException;
        }

        $opportunity = $this->transaction(function () use ($opportunity, $stage): Opportunity {
            $attributes = ['stage_id' => $stage->id];

            if ($stage->is_won) {
                $attributes['status'] = OpportunityStatus::Won->value;
                $attributes['probability'] = 100;
                $attributes['won_at'] = now();
                $attributes['closed_at'] = now();
            } elseif ($stage->is_lost) {
                $attributes['status'] = OpportunityStatus::Lost->value;
                $attributes['probability'] = 0;
                $attributes['closed_at'] = now();
            }

            $opportunity->forceFill($attributes)->save();
            $this->log->log($opportunity, ActivityType::StageChange, "Moved to stage: {$stage->name}", $opportunity->owner_id);

            return $opportunity;
        });

        return $opportunity;
    }
}
