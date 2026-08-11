<?php

declare(strict_types=1);

namespace App\Platform\AI\Assistant;

use App\Platform\AI\AiClient;
use App\Platform\AI\Enums\AiFeature;
use App\Platform\AI\Features\AdminAnalyticsAnswer;
use App\Platform\AI\Governance\AiGovernance;
use App\Platform\Shared\Analytics\Contracts\AnalyticsSummaryPort;

/**
 * The ADMIN AI ANALYTICS ASSISTANT. Answers a platform administrator's natural-language question
 * about the analytics, grounded ONLY in an aggregate KPI summary of the caller's tenant scope.
 *
 * The caller (controller) is responsible for authorization AND for resolving the tenant scope: the
 * principal MUST be an administrator holding the analytics permission; money metrics are included only
 * when they also hold the revenue permission ($includeMoney); and the organization to confine to is
 * passed in ($tenantOrgId — null for a platform-wide super_admin). This service assumes those decisions
 * were made. Everything downstream fails closed:
 *   - governance is asserted up-front (feature/tenant kill-switch) before any work; AiClient
 *     re-asserts it (defence in depth);
 *   - grounding is fetched ONLY through the Shared {@see AnalyticsSummaryPort}, which returns
 *     EXPLICITLY org-scoped AGGREGATE numbers (never per-learner PII) and OMITS money metrics entirely
 *     when $includeMoney is false — so an unpermitted admin's answer can never cite revenue;
 *   - the completion, quota and usage metering run inside {@see AiClient}.
 */
final class AdminAssistantService
{
    public function __construct(
        private readonly AnalyticsSummaryPort $analytics,
        private readonly AiClient $ai,
        private readonly AiGovernance $governance,
    ) {}

    /**
     * @param  string  $question  the administrator's untrusted question
     * @param  int  $userId  the authenticated administrator's id (authorized by caller)
     * @param  bool  $includeMoney  whether the caller is permitted currency-denominated metrics
     * @param  int|null  $tenantOrgId  the caller's organization to scope to; null = platform-wide (super_admin)
     */
    public function answer(string $question, int $userId, bool $includeMoney, ?int $tenantOrgId = null, ?string $locale = null): AdminAnalyticsAnswer
    {
        // Kill-switch gate first (feature/tenant). Throws AiFeatureDisabledException — mapped to a
        // clear disabled response by the controller. No summary is read and no provider is reached.
        $this->governance->assertEnabled(AiFeature::AdminAssistant, $tenantOrgId);

        // Aggregate grounding, EXPLICITLY org-scoped (null = super_admin platform-wide). Money metrics
        // are absent unless permitted.
        $summary = $this->analytics->summarize($includeMoney, $tenantOrgId);

        $scope = $tenantOrgId !== null ? 'your organization' : 'platform-wide';

        $result = $this->ai->chat(
            feature: AiFeature::AdminAssistant,
            promptKey: 'admin.analytics',
            variables: [
                'scope' => $scope,
                'from' => $summary->from,
                'to' => $summary->to,
                'summary' => $summary->toPromptContext(),
                'question' => $question,
            ],
            userId: $userId,
            locale: $locale,
        );

        return new AdminAnalyticsAnswer(
            content: $result->content,
            label: $result->label,
            metricsUsed: $summary->availableMetricKeys(),
            moneyIncluded: $summary->moneyIncluded,
            summary: $summary,
            requestId: $result->requestId,
            promptKey: $result->promptKey,
            promptVersion: $result->promptVersion,
        );
    }
}
