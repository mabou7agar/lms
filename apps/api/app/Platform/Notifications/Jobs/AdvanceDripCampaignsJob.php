<?php

namespace App\Platform\Notifications\Jobs;

use App\Platform\Notifications\Services\CampaignRunner;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The scheduled drip tick: advances every due campaign enrollment by one step. Runs as a system job
 * across ALL tenants (each enrollment carries its own organization_id), with tenancy explicitly
 * bypassed so the cross-tenant scan is intentional rather than an accidental scope leak.
 *
 * ShouldBeUnique + the scheduler's withoutOverlapping()/onOneServer() keep a single tick in flight;
 * the runner itself is idempotent and resumable, so an overlap or restart cannot double-send.
 */
class AdvanceDripCampaignsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ?int $limit = null) {}

    public function handle(CampaignRunner $runner, TenantContext $tenant): int
    {
        return $tenant->runWithoutTenancy(fn (): int => $runner->advanceDue($this->limit));
    }

    public function uniqueId(): string
    {
        return 'marketing-drip-advance';
    }
}
