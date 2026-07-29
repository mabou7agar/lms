'use client';

import { GRADEBOOK_DEFAULT_PER_PAGE, type GradebookOnlyFilter } from '@/lib/gradebook/gradebook-api';
import { useGradebookI18n } from '@/lib/gradebook/gradebook-i18n';

const PER_PAGE_OPTIONS = [10, 25, 50, 100] as const;

export interface GradebookFiltersProps {
  only: GradebookOnlyFilter | null;
  perPage: number;
  onOnlyChange: (only: GradebookOnlyFilter | null) => void;
  onPerPageChange: (perPage: number) => void;
}

/** Row filter (`only` = missing|late, matching GradebookQueryRequest) + page size. */
export function GradebookFilters({ only, perPage, onOnlyChange, onPerPageChange }: GradebookFiltersProps) {
  const { t } = useGradebookI18n();

  return (
    <div className="flex flex-wrap items-end gap-4" data-testid="gb-filters">
      <label className="flex flex-col gap-1 text-xs font-medium text-gray-600">
        {t('filter.label')}
        <select
          className="rounded-md border border-gray-300 px-3 py-2 text-sm"
          value={only ?? 'all'}
          onChange={(event) => {
            const value = event.target.value;
            onOnlyChange(value === 'all' ? null : (value as GradebookOnlyFilter));
          }}
          data-testid="gb-filter-only"
        >
          <option value="all">{t('filter.all')}</option>
          <option value="missing">{t('filter.missing')}</option>
          <option value="late">{t('filter.late')}</option>
        </select>
      </label>

      <label className="flex flex-col gap-1 text-xs font-medium text-gray-600">
        {t('perPage.label')}
        <select
          className="rounded-md border border-gray-300 px-3 py-2 text-sm"
          value={perPage || GRADEBOOK_DEFAULT_PER_PAGE}
          onChange={(event) => onPerPageChange(Number(event.target.value))}
          data-testid="gb-filter-per-page"
        >
          {PER_PAGE_OPTIONS.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      </label>
    </div>
  );
}
