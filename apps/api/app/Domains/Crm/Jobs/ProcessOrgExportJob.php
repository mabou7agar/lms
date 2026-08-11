<?php

namespace App\Domains\Crm\Jobs;

use App\Domains\Crm\Enums\OrgExportStatus;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrgDataExport;
use App\Domains\Crm\Services\OrgExportService;
use App\Platform\Shared\Tenancy\Concerns\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the org BI/data-export bundle from the read side, writes each CSV plus a JSON manifest to
 * private storage under a per-export prefix, and marks the row completed. The stored prefix is never
 * exposed — downloads go through a signed, org-bound route.
 *
 * TENANT SAFETY (TenantAware): on a worker there is NO authenticated user, so the tenant would resolve
 * to null and the member roster (an OrganizationMember query) would be built under a null scope. The
 * build additionally confines every dataset to the export's own organization_id (and the Shared ports
 * take an explicit organization id), so the row set is correct either way — but restoring the
 * dispatching tenant keeps the global TenantScope aligned with that same organization as a second line
 * of defense. A row's organization_id is server-resolved at request time (never client input).
 */
class ProcessOrgExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAware;

    public int $timeout = 280;

    public int $tries = 3;

    public function __construct(public readonly int $exportId)
    {
        $this->onQueue('exports');
        $this->captureTenantContext();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    /** Terminal failure: record it so the requester never waits forever on an export that never arrives. */
    public function failed(\Throwable $e): void
    {
        OrgDataExport::whereKey($this->exportId)->update(['status' => OrgExportStatus::Failed->value]);
    }

    public function handle(OrgExportService $exports): void
    {
        $export = OrgDataExport::find($this->exportId);
        if ($export === null) {
            return;
        }

        $export->forceFill(['status' => OrgExportStatus::Processing->value])->save();

        try {
            $organization = Organization::find($export->organization_id);
            if ($organization === null) {
                throw new \RuntimeException('Export organization no longer exists.');
            }

            $bundle = $exports->build($organization);

            $disk = Storage::disk((string) config('crm.export.disk', 'local'));
            $prefix = 'org-exports/'.$export->public_id;

            foreach ($bundle['files'] as $file) {
                $disk->put($prefix.'/'.$file['file'], $exports->toCsv($file['columns'], $file['rows']));
            }

            $disk->put($prefix.'/manifest.json', (string) json_encode($bundle['manifest'], JSON_PRETTY_PRINT));

            $export->forceFill([
                'status' => OrgExportStatus::Completed->value,
                'storage_prefix' => $prefix,
                'manifest' => $bundle['manifest'],
                'row_count' => (int) $bundle['manifest']['row_count'],
                'completed_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $export->forceFill(['status' => OrgExportStatus::Failed->value])->save();
            throw $e;
        }
    }
}
