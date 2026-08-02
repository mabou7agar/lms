'use client';

import { useState } from 'react';
import { Button } from '@/components/ui';
import { useGradingQueue } from '@/lib/assignments/assignments-hooks';
import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import { StatusBadge } from '../submission/StatusBadge';
import type { QueueFilter, QueuePage, QueueRow } from './types';

interface GradingQueueProps {
  assignmentId: string;
  perPage?: number;
  maxGrade?: number;
  onSelect?: (submissionId: string, row: QueueRow) => void;
  activeSubmissionId?: string;
}

const FILTERS: { value: QueueFilter; labelKey: string; fallback: string }[] = [
  { value: undefined, labelKey: 'assignments.grading.queue.filter.all', fallback: 'All' },
  { value: 'missing', labelKey: 'assignments.grading.queue.filter.missing', fallback: 'Needs grading' },
  { value: 'late', labelKey: 'assignments.grading.queue.filter.late', fallback: 'Late' },
];

function readMeta(page: QueuePage | undefined) {
  const current = page?.meta?.current_page ?? page?.current_page ?? 1;
  const last = page?.meta?.last_page ?? page?.last_page ?? 1;
  const total = page?.meta?.total ?? page?.total ?? page?.data?.length ?? 0;
  return { current, last, total };
}

/**
 * Instructor grading queue: filter (all / needs-grading / late), paginate and pick a submission to
 * grade. Backed by D3's `useGradingQueue`; carries only triage fields (no essay body / files here).
 */
export function GradingQueue({
  assignmentId,
  perPage = 20,
  maxGrade,
  onSelect,
  activeSubmissionId,
}: GradingQueueProps) {
  const { t } = useAssignmentsI18n();
  const [only, setOnly] = useState<QueueFilter>(undefined);
  const [page, setPage] = useState(1);

  const query = useGradingQueue(assignmentId, { page, per_page: perPage, only });
  const data = query.data as QueuePage | undefined;
  const rows = data?.data ?? [];
  const { current, last, total } = readMeta(data);

  const changeFilter = (value: QueueFilter) => {
    setOnly(value);
    setPage(1);
  };

  return (
    <section data-testid="grading-queue" className="space-y-3">
      <div className="flex items-center justify-between">
        <div role="tablist" aria-label={t('assignments.grading.queue.filters', 'Filters')} className="flex gap-1">
          {FILTERS.map((f) => (
            <button
              key={f.fallback}
              role="tab"
              type="button"
              aria-selected={only === f.value}
              data-testid={`queue-filter-${f.value ?? 'all'}`}
              onClick={() => changeFilter(f.value)}
              className={`rounded-md px-3 py-1 text-sm font-medium ${only === f.value ? 'bg-primary text-white' : 'bg-muted text-foreground hover:bg-muted'}`}
            >
              {t(f.labelKey, f.fallback)}
            </button>
          ))}
        </div>
        <span className="text-xs text-muted-foreground" data-testid="queue-total">
          {t('assignments.grading.queue.total', `${total} submission(s)`)}
        </span>
      </div>

      {query.isLoading ? (
        <p data-testid="queue-loading" className="text-sm text-muted-foreground">
          {t('common.loading', 'Loading…')}
        </p>
      ) : query.isError ? (
        <p role="alert" data-testid="queue-error" className="text-sm text-destructive">
          {t('assignments.grading.queue.error', 'Could not load the grading queue.')}
        </p>
      ) : rows.length === 0 ? (
        <p data-testid="queue-empty" className="text-sm text-muted-foreground">
          {t('assignments.grading.queue.empty', 'Nothing to grade here.')}
        </p>
      ) : (
        <ul className="divide-y rounded-md border border-border" data-testid="queue-rows">
          {rows.map((row) => {
            const selected = row.id === activeSubmissionId;
            return (
              <li key={row.id}>
                <button
                  type="button"
                  data-testid={`queue-row-${row.id}`}
                  aria-current={selected ? 'true' : undefined}
                  onClick={() => onSelect?.(row.id, row)}
                  className={`flex w-full items-center justify-between gap-3 px-3 py-2 text-start text-sm hover:bg-surface/40 ${selected ? 'bg-surface/40' : ''}`}
                >
                  <span className="flex items-center gap-2">
                    <span className="font-medium text-foreground">
                      {t('assignments.grading.queue.learner', `Learner ${row.learner_id}`)}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      #{row.attempt_no}
                    </span>
                    <StatusBadge status={row.status} />
                    {row.is_late && (
                      <span
                        data-testid={`queue-late-${row.id}`}
                        className="rounded bg-gold/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-foreground"
                      >
                        {t('assignments.grading.queue.late', 'Late')}
                      </span>
                    )}
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {row.released && row.score != null ? (
                      <span className="font-semibold text-foreground">
                        {row.score}
                        {maxGrade != null ? ` / ${maxGrade}` : ''}
                      </span>
                    ) : row.has_grade ? (
                      t('assignments.grading.queue.draftGrade', 'Draft grade')
                    ) : (
                      t('assignments.grading.queue.ungraded', 'Ungraded')
                    )}
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      )}

      {last > 1 && (
        <div className="flex items-center justify-between" data-testid="queue-pagination">
          <Button
            type="button"
            variant="ghost"
            disabled={current <= 1 || query.isFetching}
            data-testid="queue-prev"
            onClick={() => setPage((p) => Math.max(1, p - 1))}
          >
            {t('common.prev', 'Previous')}
          </Button>
          <span className="text-xs text-muted-foreground" data-testid="queue-page-indicator">
            {t('assignments.grading.queue.page', `Page ${current} of ${last}`)}
          </span>
          <Button
            type="button"
            variant="ghost"
            disabled={current >= last || query.isFetching}
            data-testid="queue-next"
            onClick={() => setPage((p) => Math.min(last, p + 1))}
          >
            {t('common.next', 'Next')}
          </Button>
        </div>
      )}
    </section>
  );
}
