<?php

declare(strict_types=1);

namespace App\Platform\AI\Http\Controllers;

use App\Platform\AI\Assistant\AdminAssistantService;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Analytics\AnalyticsAccess;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/ai/admin-assistant — the ADMIN AI ANALYTICS ASSISTANT.
 *
 * An administrator asks a natural-language question about the platform analytics. Access mirrors the
 * analytics KPI surface exactly (see Analytics\...\AuthorizesAnalytics), enforced here via the Shared
 * {@see AnalyticsAccess} permission slugs so the AI module never imports the Analytics context:
 *   1. the caller MUST be an administrator holding `analytics.view` (super_admin bypasses) — a
 *      non-admin / non-analytics user (student, instructor, no role) gets 403; and
 *   2. money-bearing figures require `analytics.revenue.view` (super_admin bypasses). An admin
 *      without it is still answered — but the grounding summary OMITS revenue entirely, so the
 *      assistant cannot leak a money figure it was never given.
 * Tenant scope is implicit: a super_admin (no resolved tenant) is answered platform-wide, an
 * org-admin only about their own organization. Governance + quota + usage metering run in the
 * AI foundation; a blocked call never reaches the provider.
 */
final class AdminAssistantController extends AbstractAiController
{
    public function ask(Request $request, AdminAssistantService $assistant): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $actor = $this->actor($request);

        if (! $this->canViewAnalytics($actor)) {
            return ApiResponse::error(
                'ANALYTICS_ADMIN_REQUIRED',
                'Administrator analytics access is required to use the analytics assistant.',
                [],
                403,
            );
        }

        $includeMoney = $this->canViewRevenue($actor);

        // Tenant scope: a super_admin is answered platform-wide (no tenant); any other administrator
        // is confined to their own organization's aggregates.
        $tenantOrgId = $actor->hasRole('super_admin') ? null : $actor->organizationId();

        return $this->runGuarded(fn (): JsonResponse => ApiResponse::success(
            $assistant->answer(
                question: (string) $data['question'],
                userId: $actor->actorId(),
                includeMoney: $includeMoney,
                tenantOrgId: $tenantOrgId,
            )->toArray(),
        ));
    }

    /**
     * The SAME gate as the analytics KPI endpoints: an administrator holding `analytics.view`.
     * super_admin bypasses (matching the analytics policies' before() hook); a non-administrator is
     * refused even if they somehow hold the permission, because these figures are tenant-wide.
     */
    private function canViewAnalytics(Actor $actor): bool
    {
        if ($actor->hasRole('super_admin')) {
            return true;
        }

        return $actor->hasRole('admin') && $actor->hasPermission(AnalyticsAccess::VIEW);
    }

    /** Currency-denominated metrics require the revenue permission (super_admin bypasses). */
    private function canViewRevenue(Actor $actor): bool
    {
        return $actor->hasRole('super_admin') || $actor->hasPermission(AnalyticsAccess::VIEW_REVENUE);
    }
}
