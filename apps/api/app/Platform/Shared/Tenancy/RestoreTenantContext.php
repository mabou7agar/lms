<?php

declare(strict_types=1);

namespace App\Platform\Shared\Tenancy;

/**
 * Job middleware that re-establishes the dispatching tenant on the worker for the duration of the
 * job, then clears it. Paired with the TenantAware trait. On a worker there is no authenticated user,
 * so RequestTenantResolver would resolve null and tenant-scoped queries would silently see every
 * tenant; this restores the correct boundary instead.
 *
 * A null tenant id runs the job unscoped (the pre-existing behavior), so opting a job in never
 * changes how system/console-dispatched work behaves.
 */
final class RestoreTenantContext
{
    public function __construct(private readonly int|string|null $tenantId) {}

    /**
     * @param  callable(object):mixed  $next
     */
    public function handle(object $job, callable $next): mixed
    {
        if ($this->tenantId === null) {
            return $next($job);
        }

        $context = app(TenantContext::class);
        $context->set(TenantId::from($this->tenantId));

        try {
            return $next($job);
        } finally {
            $context->forget();
        }
    }
}
