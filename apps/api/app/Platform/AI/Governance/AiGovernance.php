<?php

declare(strict_types=1);

namespace App\Platform\AI\Governance;

use App\Platform\AI\Enums\AiFeature;
use App\Platform\AI\Exceptions\AiDisabledException;
use App\Platform\AI\Exceptions\AiFeatureDisabledException;

/**
 * The governance kill-switch surface: AI enable/disable globally, per-tenant, per-feature, and
 * per-course — all config-driven (config('ai')). Overrides default to ENABLED-when-absent so AI is
 * on unless something explicitly turns it off; every gate fails closed by throwing. This is the
 * seam later features check before doing any AI work.
 */
final class AiGovernance
{
    /** Global master switch. */
    public function aiEnabled(): bool
    {
        return (bool) config('ai.enabled', false);
    }

    /** True unless this tenant is explicitly disabled in config('ai.tenant_overrides'). */
    public function tenantEnabled(?int $tenantId): bool
    {
        if ($tenantId === null) {
            return true;
        }

        /** @var array<int|string, bool> $overrides */
        $overrides = (array) config('ai.tenant_overrides', []);

        return (bool) ($overrides[$tenantId] ?? true);
    }

    /** True unless this feature is explicitly disabled in config('ai.features'). */
    public function featureEnabled(AiFeature $feature): bool
    {
        /** @var array<string, bool> $features */
        $features = (array) config('ai.features', []);

        return (bool) ($features[$feature->value] ?? true);
    }

    /** True unless this course is explicitly disabled in config('ai.course_overrides'). */
    public function courseEnabled(?int $courseId): bool
    {
        if ($courseId === null) {
            return true;
        }

        /** @var array<int|string, bool> $overrides */
        $overrides = (array) config('ai.course_overrides', []);

        return (bool) ($overrides[$courseId] ?? true);
    }

    /**
     * Fail-closed gate used by AiClient before any provider work. Throws the most specific reason.
     */
    public function assertEnabled(AiFeature $feature, ?int $tenantId = null, ?int $courseId = null): void
    {
        if (! $this->aiEnabled()) {
            throw new AiDisabledException;
        }

        if (! $this->tenantEnabled($tenantId)) {
            throw new AiFeatureDisabledException('tenant', "AI is disabled for tenant [{$tenantId}].");
        }

        if (! $this->featureEnabled($feature)) {
            throw new AiFeatureDisabledException('feature', "AI feature [{$feature->value}] is disabled.");
        }

        if (! $this->courseEnabled($courseId)) {
            throw new AiFeatureDisabledException('course', "AI is disabled for course [{$courseId}].");
        }
    }
}
