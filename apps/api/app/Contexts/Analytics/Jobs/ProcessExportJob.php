<?php

namespace App\Contexts\Analytics\Jobs;

use App\Contexts\Analytics\Enums\ExportStatus;
use App\Contexts\Analytics\Events\ExportCompleted;
use App\Contexts\Analytics\Export\ExportWriterManager;
use App\Contexts\Analytics\Models\ExportJob;
use App\Contexts\Analytics\Models\ReportDefinition;
use App\Contexts\Analytics\Services\ExportService;
use App\Platform\Shared\Tenancy\Concerns\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the export dataset from the read model, writes CSV/XLSX, stores it privately, and marks
 * the job completed. The stored path is never exposed — downloads go through a signed route.
 *
 * T1 tenant safety (TENANT-AWARE): the dataset is built by ExportService → KpiEngine, which reads the
 * tenant-scoped MetricSnapshot read model and folds the active tenant into its cache key. On a worker
 * there is NO authenticated user, so without restoration the tenant would resolve to null and the
 * export would sum GLOBAL + EVERY org's buckets (a cross-tenant leak) under the 'global' cache bucket.
 * The TenantAware trait captures the dispatching org (scalar id, server-resolved — never client input)
 * at construct/dispatch time and restores it on the worker via RestoreTenantContext, so an org1 admin's
 * export contains only global + org1 figures. A platform admin whose organization_id is null captures
 * null and the export legitimately runs unscoped (all orgs) — identical to the pre-tenancy behaviour.
 */
class ProcessExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAware;

    /** Below supervisor-exports' 300s timeout so a stalled export fails rather than being killed. */
    public int $timeout = 280;

    public int $tries = 3;

    /**
     * Long-running by nature, so it runs on its own queue.
     *
     * `default` was wrong twice over: it starved short jobs behind multi-minute report builds, and
     * supervisor-default's 60s timeout killed any export that took longer. Because the worker is
     * killed rather than throwing, handle()'s catch never ran and the row stayed `processing`
     * forever — the user saw an export that never finished and never failed. supervisor-exports
     * already exists in config/horizon.php with timeout 300; nothing was dispatching to it.
     */
    public function __construct(public readonly int $exportJobId)
    {
        $this->onQueue('exports');

        // Capture the dispatching tenant NOW, on the originating (request-resolved) context, so the
        // worker rebuilds the export under the same org boundary instead of running unscoped.
        $this->captureTenantContext();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * Terminal failure. Without this the row is left in `processing` and the user waits forever on
     * an export that will never arrive — a silent failure is worse than a reported one.
     */
    public function failed(\Throwable $e): void
    {
        // Status only — export_jobs has no error column, and the exception detail is already in
        // failed_jobs and Sentry. Inventing a column here would be a migration, not a bug fix.
        ExportJob::whereKey($this->exportJobId)->update(['status' => ExportStatus::Failed->value]);
    }

    public function handle(ExportService $exports, ExportWriterManager $writers): void
    {
        $job = ExportJob::find($this->exportJobId);
        if ($job === null) {
            return;
        }

        $job->forceFill(['status' => ExportStatus::Processing->value])->save();

        try {
            $params = (array) $job->params;
            $report = ReportDefinition::find($params['report_definition_id'] ?? null);
            $dataset = $report !== null
                ? $exports->datasetForReport($report, $params)
                : ['headers' => ['metric', 'total'], 'rows' => []];

            $writer = $writers->for($job->format->value);
            $bytes = $writer->write($dataset['headers'], $dataset['rows']);
            $path = 'exports/'.$job->public_id.'.'.$writer->extension();

            Storage::disk((string) config('analytics.export.disk', 'local'))->put($path, $bytes);

            $job->forceFill([
                'status' => ExportStatus::Completed->value,
                'file_path' => $path,
                'row_count' => count($dataset['rows']),
                'completed_at' => now(),
            ])->save();

            ExportCompleted::dispatch($job);
        } catch (\Throwable $e) {
            $job->forceFill(['status' => ExportStatus::Failed->value])->save();
            throw $e;
        }
    }
}
