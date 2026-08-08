<?php

declare(strict_types=1);

namespace App\Platform\Shared\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope for "global-OR-own-org" (nullable) tenancy — Option N of the T1 ownership matrix.
 *
 * Unlike the strict TenantScope (CRM: rows are ALWAYS owned by exactly one tenant), a shared-or-owned
 * model's rows are either GLOBAL (tenant column IS NULL — the public platform catalog) or PRIVATE to a
 * single organization (tenant column = that org). When a tenant is resolved, the owning user must see
 *   (tenant column IS NULL)  OR  (tenant column = active tenant)
 * and must NEVER see another organization's private rows.
 *
 * Filtering conditions mirror the strict scope exactly (bypass depth, bypass policy, maintenance,
 * and no-tenant => no filter), so it is backward compatible: unauthenticated/public/console/queue
 * contexts see everything, and existing rows (tenant column NULL = global) stay visible to everyone.
 *
 * Removable per query via `Model::withoutGlobalScope(SharedOrOwnedTenantScope::class)`.
 */
final class SharedOrOwnedTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        /** @var TenancyBypassPolicy $bypassPolicy */
        $bypassPolicy = app(TenancyBypassPolicy::class);
        if ($bypassPolicy->shouldBypassTenancy()) {
            return;
        }

        if (app()->isDownForMaintenance()) {
            return;
        }

        $tenantId = $context->id();

        if ($tenantId === null) {
            return;
        }

        $column = method_exists($model, 'getTenantColumn')
            ? $model->getTenantColumn()
            : app(TenantMetadata::class)->columnFor($model);

        $qualified = $model->qualifyColumn($column);

        // Global (NULL) rows OR rows owned by the active tenant — wrapped so it never widens an
        // adjacent OR clause elsewhere in the query.
        $builder->where(static function (Builder $query) use ($qualified, $tenantId): void {
            $query->whereNull($qualified)->orWhere($qualified, $tenantId->value);
        });
    }
}
