<?php

declare(strict_types=1);

namespace App\Platform\Shared\Tenancy\Concerns;

use App\Platform\Shared\Tenancy\RestoreTenantContext;
use App\Platform\Shared\Tenancy\TenantContext;

/**
 * Opt-in for QUEUED JOBS that must run under the SAME tenant as the request/context that dispatched
 * them. The existing WithoutTenancy middleware does the opposite (runs a job with tenancy bypassed
 * for system/cross-tenant work); this trait preserves and restores the ORIGINATING tenant instead.
 *
 * Contract:
 *   - Call captureTenantContext() from the job's constructor (dispatch runs on the originating,
 *     tenant-resolved context). The active tenant id is serialized onto the job as a plain scalar
 *     (never an object), per the queue's serialization rules.
 *   - middleware() re-establishes that tenant on the worker for the duration of handle(), then clears
 *     it — so a scoped query inside the job sees exactly the dispatching tenant's rows.
 *
 * A null captured id (dispatched outside any tenant, e.g. console/system) is a no-op: the job runs
 * unscoped exactly as today, preserving backward compatibility. Do NOT combine with WithoutTenancy on
 * the same job — they express opposite intents.
 */
trait TenantAware
{
    /** Scalar tenant id captured at dispatch; null when dispatched outside any tenant. */
    public int|string|null $tenantContextId = null;

    /** Capture the active tenant at dispatch time. Call this from the job constructor. */
    public function captureTenantContext(): void
    {
        $this->tenantContextId = app(TenantContext::class)->id()?->value;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RestoreTenantContext($this->tenantContextId)];
    }
}
