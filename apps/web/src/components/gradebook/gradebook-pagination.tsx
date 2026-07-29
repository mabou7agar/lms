'use client';

import { Pagination } from '@/components/ui';

import type { PaginationMeta } from '@/lib/gradebook/gradebook-api';
import { useGradebookI18n } from '@/lib/gradebook/gradebook-i18n';

export interface GradebookPaginationProps {
  meta: PaginationMeta;
  onPageChange: (page: number) => void;
}

/**
 * Pagination over the { data, meta, links } envelope. Learners are the paginated
 * unit, so thousands of rows never render at once. Reuses the shared ui
 * `Pagination` primitive (same `page` / `lastPage` / `onPageChange` API as
 * media-library-panel), with a localized range summary alongside. RTL is handled
 * by the surrounding logical layout.
 */
export function GradebookPagination({ meta, onPageChange }: GradebookPaginationProps) {
  const { t } = useGradebookI18n();

  return (
    <nav
      className="flex flex-wrap items-center justify-between gap-3"
      aria-label={t('page.title')}
      data-testid="gb-pagination"
    >
      <p className="text-sm text-gray-600" data-testid="gb-pagination-summary">
        {t('pagination.summary', {
          from: meta.from ?? 0,
          to: meta.to ?? 0,
          total: meta.total,
        })}
      </p>
      <Pagination page={meta.current_page} lastPage={meta.last_page} onPageChange={onPageChange} />
    </nav>
  );
}
