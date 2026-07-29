'use client';

import { useState } from 'react';

import { Skeleton } from '@/components/ui';
import { useAuth } from '@/lib/auth/auth-context';

import {
  GRADEBOOK_DEFAULT_PER_PAGE,
  type GradebookOnlyFilter,
  type GradebookRow,
} from '@/lib/gradebook/gradebook-api';
import { useGradebook } from '@/lib/gradebook/gradebook-hooks';
import { useGradebookI18n } from '@/lib/gradebook/gradebook-i18n';

import { GradebookExportButton } from './gradebook-export-button';
import { GradebookFilters } from './gradebook-filters';
import { GradebookPagination } from './gradebook-pagination';
import { GradebookTable } from './gradebook-table';
import { LearnerDetailDrawer } from './learner-detail-drawer';

export interface GradebookViewProps {
  publicId: string;
}

/**
 * Instructor/admin gate. The backend already 404s non-managers; this is the
 * client-side guard so the UI never renders grades to an unauthorized user.
 */
export function canManageGradebook(user: unknown): boolean {
  if (!user || typeof user !== 'object') return false;
  const u = user as { roles?: unknown; role?: unknown; is_instructor?: unknown; is_admin?: unknown };
  const allowed = new Set(['instructor', 'teacher', 'admin']);
  if (Array.isArray(u.roles) && u.roles.some((r) => typeof r === 'string' && allowed.has(r))) return true;
  if (typeof u.role === 'string' && allowed.has(u.role)) return true;
  return u.is_instructor === true || u.is_admin === true;
}

/**
 * Orchestrates the gradebook: permission gate, filters, paginated table,
 * pagination, export and the learner drawer. Handles loading / empty / error.
 */
export function GradebookView({ publicId }: GradebookViewProps) {
  const { t } = useGradebookI18n();
  const { user } = useAuth();

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(GRADEBOOK_DEFAULT_PER_PAGE);
  const [only, setOnly] = useState<GradebookOnlyFilter | null>(null);
  const [activeRow, setActiveRow] = useState<GradebookRow | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);

  const authorized = canManageGradebook(user);

  const query = { page, per_page: perPage, only };
  const { data, isLoading, isError, refetch, isFetching } = useGradebook(publicId, query, {
    enabled: authorized,
  });

  if (!authorized) {
    return (
      <section className="rounded-lg border border-gray-200 bg-white p-8 text-center" data-testid="gb-gate">
        <h2 className="text-lg font-semibold text-gray-900">{t('gate.title')}</h2>
        <p className="mt-1 text-sm text-gray-600">{t('gate.body')}</p>
      </section>
    );
  }

  const handleOnly = (next: GradebookOnlyFilter | null) => {
    setOnly(next);
    setPage(1);
  };
  const handlePerPage = (next: number) => {
    setPerPage(next);
    setPage(1);
  };
  const openLearner = (row: GradebookRow) => {
    setActiveRow(row);
    setDrawerOpen(true);
  };

  return (
    <div className="flex flex-col gap-6" data-testid="gb-view">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">{t('page.title')}</h1>
          <p className="mt-1 text-sm text-gray-600">{t('page.subtitle')}</p>
        </div>
        <GradebookExportButton publicId={publicId} />
      </header>

      <GradebookFilters
        only={only}
        perPage={perPage}
        onOnlyChange={handleOnly}
        onPerPageChange={handlePerPage}
      />

      {isLoading ? (
        <div className="flex flex-col gap-2" data-testid="gb-loading" aria-busy="true">
          <span className="sr-only">{t('state.loading')}</span>
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
        </div>
      ) : isError ? (
        <section className="rounded-lg border border-red-200 bg-red-50 p-8 text-center" data-testid="gb-error">
          <h2 className="text-lg font-semibold text-red-800">{t('state.error.title')}</h2>
          <p className="mt-1 text-sm text-red-700">{t('state.error.body')}</p>
          <button
            type="button"
            onClick={() => refetch()}
            className="mt-4 rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white"
            data-testid="gb-retry"
          >
            {t('state.retry')}
          </button>
        </section>
      ) : !data || data.data.length === 0 ? (
        <section className="rounded-lg border border-gray-200 bg-white p-8 text-center" data-testid="gb-empty">
          <h2 className="text-lg font-semibold text-gray-900">{t('state.empty.title')}</h2>
          <p className="mt-1 text-sm text-gray-600">{t('state.empty.body')}</p>
        </section>
      ) : (
        <div className="flex flex-col gap-4" aria-busy={isFetching}>
          <GradebookTable rows={data.data} onOpenLearner={openLearner} />
          <GradebookPagination meta={data.meta} onPageChange={setPage} />
        </div>
      )}

      <LearnerDetailDrawer row={activeRow} open={drawerOpen} onOpenChange={setDrawerOpen} />
    </div>
  );
}
