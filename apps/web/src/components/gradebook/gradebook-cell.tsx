'use client';

import type { GradebookCell as GradebookCellData } from '@/lib/gradebook/gradebook-api';
import { useGradebookI18n } from '@/lib/gradebook/gradebook-i18n';

import { CellBadges } from './gradebook-status-badge';

export interface GradebookCellProps {
  cell: GradebookCellData;
}

function formatScore(cell: GradebookCellData, t: ReturnType<typeof useGradebookI18n>['t']): string {
  if (cell.missing) return t('cell.noScore');
  if (cell.score !== null && cell.max != null) {
    return t('cell.score', { score: cell.score, max: cell.max });
  }
  if (cell.score !== null) {
    return String(cell.score);
  }
  if (cell.percent !== null) {
    return t('cell.percent', { percent: cell.percent });
  }
  return t('cell.noScore');
}

/** One data cell: a score line plus status badges. */
export function GradebookCellContent({ cell }: GradebookCellProps) {
  const { t } = useGradebookI18n();
  return (
    <div className="flex flex-col gap-1" data-testid={`gb-cell-${cell.ref}`}>
      <span className="text-sm font-medium tabular-nums text-foreground">{formatScore(cell, t)}</span>
      {cell.percent !== null && cell.score !== null ? (
        <span className="text-xs text-muted-foreground tabular-nums">{t('cell.percent', { percent: cell.percent })}</span>
      ) : null}
      <CellBadges cell={cell} />
    </div>
  );
}
