'use client';

import { useAssignmentsI18n } from '@/lib/assignments/assignments-i18n';
import type { LearnerSubmission } from './types';
import { StatusBadge } from './StatusBadge';

interface SubmissionHistoryProps {
  submissions: LearnerSubmission[];
  maxGrade?: number;
  activeSubmissionId?: string;
  onSelect?: (submission: LearnerSubmission) => void;
}

function formatDate(iso: string | null): string {
  if (!iso) return '—';
  const ms = Date.parse(iso);
  return Number.isFinite(ms) ? new Date(ms).toLocaleString() : '—';
}

/**
 * Chronological list of the learner's attempts. Shows the released score only when a released grade
 * is present on that attempt — never a private note (the learner resource never carries one).
 */
export function SubmissionHistory({
  submissions,
  maxGrade,
  activeSubmissionId,
  onSelect,
}: SubmissionHistoryProps) {
  const { t } = useAssignmentsI18n();

  if (submissions.length === 0) {
    return (
      <p data-testid="history-empty" className="text-sm text-muted-foreground">
        {t('assignments.submission.history.empty', 'No attempts yet.')}
      </p>
    );
  }

  const ordered = [...submissions].sort((a, b) => b.attempt_no - a.attempt_no);

  return (
    <section data-testid="submission-history" className="space-y-2">
      <h3 className="text-sm font-semibold text-foreground">
        {t('assignments.submission.history.title', 'Submission history')}
      </h3>
      <ul className="divide-y rounded-md border border-border">
        {ordered.map((s) => {
          const released = s.grade;
          const selected = s.id === activeSubmissionId;
          return (
            <li key={s.id}>
              <button
                type="button"
                data-testid={`history-row-${s.attempt_no}`}
                aria-current={selected ? 'true' : undefined}
                onClick={() => onSelect?.(s)}
                className={`flex w-full items-center justify-between gap-3 px-3 py-2 text-start text-sm hover:bg-surface/40 ${selected ? 'bg-surface/40' : ''}`}
              >
                <span className="flex items-center gap-2">
                  <span className="font-medium text-foreground">
                    {t('assignments.submission.history.attempt', `Attempt ${s.attempt_no}`)}
                  </span>
                  <StatusBadge status={s.status} />
                  {s.is_late && (
                    <span
                      data-testid="history-late"
                      className="rounded bg-gold/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-foreground"
                    >
                      {t('assignments.submission.history.late', 'Late')}
                    </span>
                  )}
                </span>
                <span className="flex items-center gap-3 text-xs text-muted-foreground">
                  <span>{formatDate(s.submitted_at)}</span>
                  {released && released.score != null && (
                    <span className="font-semibold text-foreground" data-testid="history-score">
                      {released.score}
                      {maxGrade != null ? ` / ${maxGrade}` : ''}
                    </span>
                  )}
                </span>
              </button>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
