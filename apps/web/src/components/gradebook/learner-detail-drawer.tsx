'use client';

import {
  Drawer,
  DrawerContent,
  DrawerDescription,
  DrawerHeader,
  DrawerTitle,
} from '@/components/ui/drawer';

import type { GradebookRow } from '@/lib/gradebook/gradebook-api';
import { useGradebookI18n } from '@/lib/gradebook/gradebook-i18n';

import { CellBadges } from './gradebook-status-badge';

export interface LearnerDetailDrawerProps {
  row: GradebookRow | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

/**
 * Per-learner drawer: overall roll-up plus a per-item (assignment/quiz) list.
 * Uses the shared composed Drawer primitive (same shape as media-details-drawer).
 */
export function LearnerDetailDrawer({ row, open, onOpenChange }: LearnerDetailDrawerProps) {
  const { t } = useGradebookI18n();

  return (
    <Drawer open={open && row !== null} onOpenChange={onOpenChange}>
      <DrawerContent className="mx-auto max-h-[85vh] w-full max-w-lg overflow-y-auto" data-testid="gb-drawer">
        <DrawerHeader>
          <DrawerTitle>{row ? t('drawer.title', { id: row.user_id }) : ''}</DrawerTitle>
          <DrawerDescription className="sr-only">{t('drawer.columns')}</DrawerDescription>
        </DrawerHeader>

        {row ? (
          <div className="flex flex-col gap-4 px-4 pb-4" data-testid="gb-drawer-body">
            <section>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">{t('drawer.overall')}</h3>
              <p className="mt-1 text-2xl font-semibold tabular-nums text-gray-900">
                {row.summary.average_percent === null
                  ? t('summary.noAverage')
                  : t('summary.average', { percent: row.summary.average_percent })}
              </p>
              <div className="mt-1 flex flex-wrap gap-3 text-sm text-gray-600">
                <span>{t('summary.passed', { count: row.summary.passed_count })}</span>
                <span>{t('summary.missing', { count: row.summary.missing_count })}</span>
              </div>
            </section>

            <section>
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">{t('drawer.columns')}</h3>
              <ul className="mt-2 flex flex-col divide-y divide-gray-100">
                {row.cells.map((cell, index) => (
                  <li
                    key={`${cell.ref}:${index}`}
                    className="flex items-start justify-between gap-4 py-2"
                    data-testid={`gb-drawer-item-${cell.ref}`}
                  >
                    <div className="flex flex-col">
                      <span className="text-[10px] font-medium uppercase tracking-wide text-gray-400">
                        {t(`colType.${cell.type}`)}
                      </span>
                      <span className="text-sm font-medium text-gray-900">{cell.title}</span>
                    </div>
                    <div className="flex flex-col items-end gap-1">
                      <span className="text-sm tabular-nums text-gray-700">
                        {cell.missing
                          ? t('cell.noScore')
                          : cell.percent !== null
                            ? t('cell.percent', { percent: cell.percent })
                            : cell.score !== null
                              ? String(cell.score)
                              : t('cell.noScore')}
                      </span>
                      <CellBadges cell={cell} />
                    </div>
                  </li>
                ))}
              </ul>
            </section>
          </div>
        ) : null}
      </DrawerContent>
    </Drawer>
  );
}
