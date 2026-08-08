<?php

declare(strict_types=1);

namespace App\Platform\Shared\Tenancy\Concerns;

use App\Platform\Shared\Tenancy\SharedOrOwnedTenantScope;
use App\Platform\Shared\Tenancy\TenancyBypassPolicy;
use App\Platform\Shared\Tenancy\TenantContext;
use App\Platform\Shared\Tenancy\TenantId;
use App\Platform\Shared\Tenancy\TenantMetadata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Opt-in "global-OR-own-org" (nullable) tenancy for an Eloquent model — Option N of the T1 matrix.
 * A model using this trait is:
 *   - FILTERED (via SharedOrOwnedTenantScope) to global rows (tenant column NULL) plus the active
 *     tenant's own rows when a tenant is resolved — never another tenant's private rows,
 *   - STAMPED with the active tenant on create WHEN a tenant is resolved; when none is resolved the
 *     column stays NULL, i.e. the row is created as GLOBAL platform content.
 *
 * This is the counterpart of the strict BelongsToTenant used by CRM (where rows are always owned by
 * exactly one tenant). A model uses EXACTLY ONE of the two traits — never both. This trait is kept
 * standalone (its own copies of the column/ownership helpers) so it never alters the strict trait,
 * which CRM relies on.
 *
 * Tenant column resolution (TenantMetadata / a model `protected string $tenantColumn`) and the
 * bypass rules (bypass depth, RoleBasedTenancyBypassPolicy, maintenance) are identical to the strict
 * path. Composes cleanly with SoftDeletes.
 *
 * @mixin Model
 */
trait BelongsToTenantNullable
{
    protected static function bootBelongsToTenantNullable(): void
    {
        static::addGlobalScope(new SharedOrOwnedTenantScope);

        static::creating(static function (Model $model): void {
            if (method_exists($model, 'assignTenantOnCreate')) {
                $model->assignTenantOnCreate();
            }
        });
    }

    /** The column that stores the owning tenant id: a model override, else config-driven metadata. */
    public function getTenantColumn(): string
    {
        if (property_exists($this, 'tenantColumn')) {
            return $this->tenantColumn;
        }

        return app(TenantMetadata::class)->columnFor($this);
    }

    /**
     * Stamp the active tenant on create when present and not already set (and not bypassed). When no
     * tenant is resolved the column is left NULL — the row is created as GLOBAL/public content.
     * The tenant is NEVER taken from client input: it comes only from the resolved TenantContext.
     */
    public function assignTenantOnCreate(): void
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        /** @var TenancyBypassPolicy $bypassPolicy */
        $bypassPolicy = app(TenancyBypassPolicy::class);

        if ($context->isBypassed() || $bypassPolicy->shouldBypassTenancy()) {
            return;
        }

        $tenantId = $context->id();
        $column = $this->getTenantColumn();

        if ($tenantId !== null && $this->getAttribute($column) === null) {
            $this->setAttribute($column, $tenantId->value);
        }
    }

    /** True when this record is global (no tenant) or belongs to the given tenant. */
    public function isVisibleToTenant(TenantId $tenantId): bool
    {
        $owner = $this->getAttribute($this->getTenantColumn());

        return $owner === null || (string) $owner === $tenantId->toString();
    }

    /** True when this record is private to the given tenant (not global). */
    public function belongsToTenant(TenantId $tenantId): bool
    {
        $owner = $this->getAttribute($this->getTenantColumn());

        return $owner !== null && (string) $owner === $tenantId->toString();
    }

    /** True when this record is global platform content (no owning tenant). */
    public function isGlobal(): bool
    {
        return $this->getAttribute($this->getTenantColumn()) === null;
    }

    /** Explicit scope: global rows plus the given tenant's own rows. */
    public function scopeForTenant(Builder $query, TenantId $tenantId): Builder
    {
        $column = $this->getTenantColumn();

        return $query->where(function (Builder $q) use ($column, $tenantId): void {
            $q->whereNull($column)->orWhere($column, $tenantId->value);
        });
    }

    /** Explicit scope: ONLY the given tenant's private rows (excludes global). */
    public function scopeOwnedByTenant(Builder $query, TenantId $tenantId): Builder
    {
        return $query->where($this->getTenantColumn(), $tenantId->value);
    }
}
