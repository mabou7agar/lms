<?php

declare(strict_types=1);

namespace App\Platform\AI\Metering;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin-facing, platform-wide usage/spend rollups for the current month: total spend and tokens,
 * top features, top organizations, current global-quota state, and a naive month-end cost
 * projection. Uses the query builder (no tenant global scope) since this is a deliberate
 * cross-tenant administration view — never exposed to tenant users.
 */
final class AiUsageSummary
{
    private const TABLE = 'ai_usages';

    /**
     * @return array{
     *   month: string,
     *   total_spend_micros: int,
     *   total_tokens: int,
     *   calls: int,
     *   top_features: list<array{feature: string, tokens: int, cost_micros: int, calls: int}>,
     *   top_organizations: list<array{organization_id: int|null, tokens: int, cost_micros: int, calls: int}>,
     *   quota: array{global_monthly_tokens: int, global_used_tokens: int, global_remaining_tokens: int|null},
     *   estimated_month_end_cost_micros: int
     * }
     */
    public function overview(?Carbon $month = null): array
    {
        $monthStart = ($month ?? Carbon::now())->copy()->startOfMonth();

        $spend = (int) DB::table(self::TABLE)->where('created_at', '>=', $monthStart)->sum('estimated_cost_micros');
        $tokens = (int) DB::table(self::TABLE)->where('created_at', '>=', $monthStart)->sum('input_tokens')
            + (int) DB::table(self::TABLE)->where('created_at', '>=', $monthStart)->sum('output_tokens');
        $calls = (int) DB::table(self::TABLE)->where('created_at', '>=', $monthStart)->count();

        $globalLimit = (int) config('ai.limits.global_monthly_tokens', 0);
        $remaining = $globalLimit > 0 ? max(0, $globalLimit - $tokens) : null;

        // Naive linear month-end projection from days elapsed (never below today's spend).
        $daysElapsed = max(1, (int) $monthStart->diffInDays(Carbon::now()) + 1);
        $daysInMonth = (int) $monthStart->daysInMonth;
        $projected = (int) round(($spend / $daysElapsed) * $daysInMonth);

        return [
            'month' => $monthStart->format('Y-m'),
            'total_spend_micros' => $spend,
            'total_tokens' => $tokens,
            'calls' => $calls,
            'top_features' => $this->topFeatures($monthStart),
            'top_organizations' => $this->topOrganizations($monthStart),
            'quota' => [
                'global_monthly_tokens' => $globalLimit,
                'global_used_tokens' => $tokens,
                'global_remaining_tokens' => $remaining,
            ],
            'estimated_month_end_cost_micros' => max($spend, $projected),
        ];
    }

    /**
     * @return list<array{feature: string, tokens: int, cost_micros: int, calls: int}>
     */
    private function topFeatures(Carbon $monthStart): array
    {
        $rows = DB::table(self::TABLE)
            ->where('created_at', '>=', $monthStart)
            ->groupBy('feature')
            ->selectRaw('feature, COALESCE(SUM(input_tokens+output_tokens),0) as tokens, COALESCE(SUM(estimated_cost_micros),0) as cost, COUNT(*) as calls')
            ->orderByDesc('tokens')
            ->get();

        return $rows->map(function ($row): array {
            /** @var array<string, mixed> $r */
            $r = (array) $row;

            return [
                'feature' => (string) ($r['feature'] ?? ''),
                'tokens' => (int) ($r['tokens'] ?? 0),
                'cost_micros' => (int) ($r['cost'] ?? 0),
                'calls' => (int) ($r['calls'] ?? 0),
            ];
        })->all();
    }

    /**
     * @return list<array{organization_id: int|null, tokens: int, cost_micros: int, calls: int}>
     */
    private function topOrganizations(Carbon $monthStart): array
    {
        $rows = DB::table(self::TABLE)
            ->where('created_at', '>=', $monthStart)
            ->groupBy('organization_id')
            ->selectRaw('organization_id, COALESCE(SUM(input_tokens+output_tokens),0) as tokens, COALESCE(SUM(estimated_cost_micros),0) as cost, COUNT(*) as calls')
            ->orderByDesc('tokens')
            ->limit(20)
            ->get();

        return $rows->map(function ($row): array {
            /** @var array<string, mixed> $r */
            $r = (array) $row;

            return [
                'organization_id' => isset($r['organization_id']) && $r['organization_id'] !== null ? (int) $r['organization_id'] : null,
                'tokens' => (int) ($r['tokens'] ?? 0),
                'cost_micros' => (int) ($r['cost'] ?? 0),
                'calls' => (int) ($r['calls'] ?? 0),
            ];
        })->all();
    }
}
