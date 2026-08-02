'use client';

import { deriveColumns, type GradebookColumn, type GradebookRow } from '@/lib/gradebook/gradebook-api';
import { useGradebookI18n } from '@/lib/gradebook/gradebook-i18n';

import { GradebookCellContent } from './gradebook-cell';
import { RowSummary } from './gradebook-row-summary';

export interface GradebookTableProps {
  rows: GradebookRow[];
  onOpenLearner: (row: GradebookRow) => void;
}

/**
 * The gradebook grid. Learners are rows (one page at a time); columns are the
 * assignment + quiz items derived from the row cells. Horizontally scrollable
 * with a pinned learner column. RTL-safe: pinning uses the logical `start` edge
 * so the learner column stays on the reading-start side in both directions.
 */
export function GradebookTable({ rows, onOpenLearner }: GradebookTableProps) {
  const { t } = useGradebookI18n();
  const columns = deriveColumns(rows);

  return (
    <div className="overflow-x-auto rounded-lg border border-border" data-testid="gb-table-scroll">
      <table className="min-w-full border-collapse text-start" data-testid="gb-table">
        <caption className="sr-only">{t('page.title')}</caption>
        <thead>
          <tr className="bg-surface/40">
            <th
              scope="col"
              className="sticky start-0 z-20 bg-surface/40 px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-muted-foreground"
            >
              {t('column.learner')}
            </th>
            {columns.map((col: GradebookColumn) => (
              <th
                key={`${col.type}:${col.ref}`}
                scope="col"
                className="whitespace-nowrap px-4 py-3 text-start text-xs font-semibold text-muted-foreground"
                data-testid={`gb-col-${col.ref}`}
              >
                <span className="block text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                  {t(`colType.${col.type}`)}
                </span>
                {col.title}
              </th>
            ))}
            <th
              scope="col"
              className="whitespace-nowrap px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-muted-foreground"
            >
              {t('column.overall')}
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.user_id} className="border-t border-border/60 hover:bg-surface/40" data-testid={`gb-row-${row.user_id}`}>
              <th
                scope="row"
                className="sticky start-0 z-10 bg-white px-4 py-3 text-start align-top font-normal"
              >
                <button
                  type="button"
                  onClick={() => onOpenLearner(row)}
                  className="text-sm font-medium text-primary underline-offset-2 hover:underline"
                  aria-label={t('drawer.open')}
                  data-testid={`gb-open-${row.user_id}`}
                >
                  {t('learner.label', { id: row.user_id })}
                </button>
              </th>
              {columns.map((col) => (
                <td key={`${row.user_id}:${col.index}`} className="px-4 py-3 align-top">
                  <GradebookCellContent cell={row.cells[col.index]} />
                </td>
              ))}
              <td className="px-4 py-3 align-top">
                <RowSummary summary={row.summary} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
