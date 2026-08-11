<?php

declare(strict_types=1);

namespace App\Platform\Shared\Analytics\Data;

/**
 * An AGGREGATE analytics KPI summary for one tenant scope — the grounded context the Admin AI
 * Analytics Assistant answers from.
 *
 * It carries ONLY organization-level totals (enrollments, completions, a derived completion rate,
 * signups, certificates, and — when the caller is permitted — revenue/orders) plus optional
 * per-course enrollment totals. There is deliberately no field that could hold an individual
 * learner's identity: every value here is a sum over the metric_snapshots read model.
 *
 * Money metrics are present in `$metrics` ONLY when they were permitted; when withheld they are
 * absent from the map (never a zero, never a null placeholder), so `moneyIncluded === false` and the
 * prompt context literally cannot mention revenue.
 */
final readonly class AnalyticsSummary
{
    /**
     * @param  array<string, MetricValue>  $metrics  metric key => value; money keys present only when permitted
     * @param  array<int, array{label: string, enrollments: int}>  $topCourses  aggregate enrollments per course (course labels, no PII)
     */
    public function __construct(
        public string $from,
        public string $to,
        public array $metrics,
        public array $topCourses,
        public bool $moneyIncluded,
    ) {}

    /**
     * Keys of the metrics that were actually computed (available), for the assistant's
     * "which metrics were used" disclosure.
     *
     * @return list<string>
     */
    public function availableMetricKeys(): array
    {
        $keys = [];
        foreach ($this->metrics as $key => $value) {
            if ($value->available) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * A numbered, PII-free context block for grounding the model. Empty-safe: with nothing to report
     * it says so, so the prompt instructs the model to answer that no data is available rather than
     * inventing figures.
     */
    public function toPromptContext(): string
    {
        $lines = [];
        $n = 1;

        foreach ($this->metrics as $key => $value) {
            if (! $value->available) {
                continue;
            }
            $lines[] = '['.$n++.'] '.$this->humanize($key).': '.$this->format($value->value);
        }

        foreach ($this->topCourses as $course) {
            $lines[] = '['.$n++.'] Top course '.$course['label'].': '.$course['enrollments'].' enrollments';
        }

        if ($lines === []) {
            return 'No aggregate metrics are available for this period.';
        }

        return implode("\n", $lines);
    }

    /**
     * The API payload: the aggregate figures the answer was grounded in.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $metrics = [];
        foreach ($this->metrics as $key => $value) {
            $metrics[$key] = $value->toArray();
        }

        return [
            'from' => $this->from,
            'to' => $this->to,
            'money_included' => $this->moneyIncluded,
            'metrics' => $metrics,
            'top_courses' => $this->topCourses,
        ];
    }

    private function humanize(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    private function format(int|float|null $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
        }

        return (string) $value;
    }
}
