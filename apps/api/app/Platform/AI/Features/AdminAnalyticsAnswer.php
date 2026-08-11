<?php

declare(strict_types=1);

namespace App\Platform\AI\Features;

use App\Platform\Shared\Analytics\Data\AnalyticsSummary;

/**
 * The result of an Admin AI Analytics Assistant call: the AI-labelled answer, the aggregate KPI
 * summary it was grounded in, and the disclosure of exactly which metric keys informed it (plus
 * whether money metrics were part of the scope). It ties back to its ai_usages row via the request
 * id + prompt key/version.
 *
 * There is intentionally no citation of a per-learner record — the assistant grounds in tenant-level
 * aggregates only, so the "sources" here are metric keys, not people.
 */
final class AdminAnalyticsAnswer
{
    /**
     * @param  list<string>  $metricsUsed  aggregate metric keys the answer was grounded in
     */
    public function __construct(
        public readonly string $content,
        public readonly ?string $label,
        public readonly array $metricsUsed,
        public readonly bool $moneyIncluded,
        public readonly AnalyticsSummary $summary,
        public readonly ?string $requestId = null,
        public readonly ?string $promptKey = null,
        public readonly ?int $promptVersion = null,
    ) {}

    /**
     * The API payload: the labelled answer, the metrics used, and the grounding summary.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'answer' => $this->content,
            'label' => $this->label,
            'refused' => false,
            'metrics_used' => $this->metricsUsed,
            'money_included' => $this->moneyIncluded,
            'summary' => $this->summary->toArray(),
            'request_id' => $this->requestId,
            'prompt' => $this->promptKey === null ? null : [
                'key' => $this->promptKey,
                'version' => $this->promptVersion,
            ],
        ];
    }
}
