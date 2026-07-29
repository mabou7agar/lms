'use client';

import { Badge } from '@/components/ui';

import { cellStatus, type CellStatus, type GradebookCell } from '@/lib/gradebook/gradebook-api';
import { useGradebookI18n } from '@/lib/gradebook/gradebook-i18n';

/** Badge variant per semantic cell status (matches the shared ui Badge variants). */
const VARIANT = {
  missing: 'destructive',
  late: 'warning',
  passed: 'success',
  failed: 'destructive',
  unreleased: 'outline',
  graded: 'info',
  pending: 'secondary',
} as const satisfies Record<CellStatus, string>;

export interface GradebookStatusBadgeProps {
  status: CellStatus;
  className?: string;
}

/** A single semantic status pill (missing / late / passed / failed / …). */
export function GradebookStatusBadge({ status, className }: GradebookStatusBadgeProps) {
  const { t } = useGradebookI18n();
  return (
    <Badge variant={VARIANT[status]} className={className} data-testid={`gb-status-${status}`}>
      {t(`status.${status}`)}
    </Badge>
  );
}

export interface CellBadgesProps {
  cell: GradebookCell;
}

/**
 * The full set of indicators for a cell: the primary status plus a `late` badge
 * when a graded/passed cell was also submitted late (late is otherwise primary).
 */
export function CellBadges({ cell }: CellBadgesProps) {
  const primary = cellStatus(cell);
  return (
    <span className="inline-flex flex-wrap items-center gap-1">
      <GradebookStatusBadge status={primary} />
      {cell.is_late && primary !== 'late' ? <GradebookStatusBadge status="late" /> : null}
    </span>
  );
}
