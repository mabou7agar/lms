'use client';

import { Badge } from '@/components/ui';

import type { GradebookRowSummary } from '@/lib/gradebook/gradebook-api';
import { useGradebookI18n } from '@/lib/gradebook/gradebook-i18n';

export interface RowSummaryProps {
  summary: GradebookRowSummary;
}

/** Overall roll-up for a learner row: average %, passed and missing counts. */
export function RowSummary({ summary }: RowSummaryProps) {
  const { t } = useGradebookI18n();
  return (
    <div className="flex flex-col gap-1" data-testid="gb-row-summary">
      <span className="text-sm font-semibold tabular-nums text-foreground">
        {summary.average_percent === null
          ? t('summary.noAverage')
          : t('summary.average', { percent: summary.average_percent })}
      </span>
      <span className="inline-flex flex-wrap items-center gap-1">
        {summary.passed_count > 0 ? (
          <Badge variant="success" data-testid="gb-summary-passed">
            {t('summary.passed', { count: summary.passed_count })}
          </Badge>
        ) : null}
        {summary.missing_count > 0 ? (
          <Badge variant="destructive" data-testid="gb-summary-missing">
            {t('summary.missing', { count: summary.missing_count })}
          </Badge>
        ) : null}
      </span>
    </div>
  );
}
