<?php

namespace App\Domains\Crm\Actions\Opportunity;

use App\Domains\Crm\Enums\ActivityType;
use App\Domains\Crm\Enums\OpportunityStatus;
use App\Domains\Crm\Models\Company;
use App\Domains\Crm\Models\Lead;
use App\Domains\Crm\Models\Opportunity;
use App\Domains\Crm\Models\Pipeline;
use App\Domains\Crm\Services\ActivityLogger;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Creates a pipeline Opportunity, optionally seeded from a Lead (convert-from-lead: the deal
 * inherits the lead's company + value). Placed at the first stage of the chosen (or default)
 * pipeline; every creation is written to the opportunity timeline.
 */
class CreateOpportunityAction extends BaseAction
{
    public function __construct(private readonly ActivityLogger $log) {}

    /** @param array<string, mixed> $data */
    public function execute(
        array $data,
        ?Lead $lead = null,
        ?Pipeline $pipeline = null,
        ?Company $company = null,
        int|string|null $ownerId = null,
    ): Opportunity {
        $opportunity = $this->transaction(function () use ($data, $lead, $pipeline, $company, $ownerId): Opportunity {
            $pipeline ??= Pipeline::where('is_default', true)->first();
            $stage = $pipeline?->stages()->orderBy('position')->first();
            $companyId = $company?->id ?? $lead?->company_id;

            return Opportunity::create([
                'lead_id' => $lead?->id,
                'company_id' => $companyId,
                'pipeline_id' => $pipeline?->id,
                'stage_id' => $stage?->id,
                'owner_id' => $ownerId,
                'name' => $data['name'],
                'amount_minor' => $data['amount_minor'] ?? $lead?->value_minor,
                'currency' => $data['currency'] ?? $lead?->currency,
                'probability' => (int) ($data['probability'] ?? 0),
                'product_ref' => $data['product_ref'] ?? null,
                'status' => OpportunityStatus::Open->value,
                'expected_close_date' => $data['expected_close_date'] ?? null,
            ]);
        });

        $this->log->log($opportunity, ActivityType::System, 'Opportunity created', $ownerId);

        return $opportunity;
    }
}
