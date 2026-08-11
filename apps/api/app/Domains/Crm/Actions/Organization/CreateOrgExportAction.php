<?php

namespace App\Domains\Crm\Actions\Organization;

use App\Domains\Crm\Enums\OrgExportStatus;
use App\Domains\Crm\Jobs\ProcessOrgExportJob;
use App\Domains\Crm\Models\OrgDataExport;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Queues an org BI/data export: records the request (confined to the caller's organization) and
 * dispatches the build job AFTER the row is committed, so the worker can always find it.
 */
class CreateOrgExportAction extends BaseAction
{
    public function executeForOrganization(int $organizationId, ?int $requestedByUserId): OrgDataExport
    {
        $export = $this->transaction(function () use ($organizationId, $requestedByUserId): OrgDataExport {
            return OrgDataExport::create([
                'organization_id' => $organizationId,
                'requested_by_user_id' => $requestedByUserId,
                'dataset' => 'bi_bundle',
                'status' => OrgExportStatus::Queued->value,
            ]);
        });

        ProcessOrgExportJob::dispatch($export->id)->afterCommit();

        return $export;
    }
}
